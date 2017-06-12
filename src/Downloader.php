<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex;

use Composer\Cache;
use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Downloader\TransportException;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Downloader
{
    private const DEFAULT_ENDPOINT = 'https://symfony.sh';

    private $io;
    private $sess;
    private $cache;
    private $rfs;
    private $degradedMode = false;
    private $endpoint;
    private $caFile;
    private $flexId;
    private $allowContrib = false;

    public function __construct(Composer $composer, IoInterface $io)
    {
        if (getenv('SYMFONY_CAFILE')) {
            $this->caFile = getenv('SYMFONY_CAFILE');
        }
        if (getenv('SYMFONY_ENDPOINT')) {
            $endpoint = getenv('SYMFONY_ENDPOINT');
        } else {
            $endpoint = $composer->getPackage()->getExtra()['symfony']['endpoint'] ?? self::DEFAULT_ENDPOINT;
        }
        $this->endpoint = rtrim($endpoint, '/');
        $this->io = $io;
        $config = $composer->getConfig();
        $this->rfs = Factory::createRemoteFilesystem($io, $config);
        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->endpoint));
        $this->sess = bin2hex(random_bytes(16));
    }

    public function setFlexId(?string $id): void
    {
        $this->flexId = $id;
    }

    public function allowContrib(bool $allow): void
    {
        $this->allowContrib = $allow;
    }

    /**
     * Downloads recipes.
     *
     * @param OperationInterface[] $operations
     *
     * @return Recipe[]
     */
    public function getRecipes(array $operations)
    {
        $max = 1000;
        $urls = [];
        $chunk = '';
        foreach ($operations as $i => $operation) {
            if ($operation instanceof UpdateOperation) {
                $package = $operation->getTargetPackage();
            } else {
                $package = $operation->getPackage();
            }

            $version = $package->getPrettyVersion();
            if (0 === strpos($version, 'dev-') && isset($package->getExtra()['branch-alias'])) {
                $branchAliases = $package->getExtra()['branch-alias'];
                if (($alias = $branchAliases[$version]) || ($alias = $branchAliases['dev-master'])) {
                    $version = $alias;
                }
            }
            $o = 'i';
            if ('uninstall' === $operation->getJobType()) {
                $o = 'r';
            } elseif ('update' === $operation->getJobType()) {
                $o = 'u';
            }

            // FIXME: getNames() can return n names
            $name = str_replace('/', ',', $package->getNames()[0]);
            $url = sprintf('%s,%s%s', $name, $o, $version);
            if ($date = $package->getReleaseDate()) {
                $url .= ','.$date->format('U');
            }
            if (strlen($chunk) + strlen($url) > 1000) {
                $urls[] = '/p/'.$chunk;
                $chunk = '';
            } elseif ($chunk) {
                $chunk .= ';'.$url;
            } else {
                $chunk = $url;
            }
        }
        if ($chunk) {
            $urls[] = '/p/'.$chunk;
        }

        $data = [];
        foreach ($urls as $url) {
            $response = $this->get($url, ['Package-Session: '.$this->sess], false);
            foreach ($response->getBody() as $name => $manifest) {
                $data[$name] = $manifest;
            }
        }

        return $data;
    }

    /**
     * Decodes a JSON HTTP response body.
     *
     * @param string $path    The path to get on the server
     * @param array  $headers An array of HTTP headers
     */
    public function get(string $path, array $headers = [], $cache = true): Response
    {
        $headers[] = 'Package-Session: '.$this->sess;
        $url = $this->endpoint.'/'.ltrim($path, '/');
        $cacheKey = $cache ? ltrim($path, '/') : '';

        try {
            if ($cacheKey && $contents = $this->cache->read($cacheKey)) {
                $cachedResponse = Response::fromJson(json_decode($contents, true));
                if ($lastModified = $cachedResponse->getHeader('last-modified')) {
                    $response = $this->fetchFileIfLastModified($url, $cacheKey, $lastModified, $headers);
                    if (304 === $response->getStatusCode()) {
                        $response = new Response($cachedResponse->getBody(), $response->getOrigHeaders(), 304);
                    }

                    return $response;
                }
            }

            return $this->fetchFile($url, $cacheKey, $headers);
        } catch (TransportException $e) {
            if (404 === $e->getStatusCode()) {
                return new Response($e->getResponse(), $e->getHeaders(), 404);
            }

            throw $e;
        }
    }

    private function fetchFile(string $url, string $cacheKey, iterable $headers): Response
    {
        $options = $this->getOptions($headers);
        $retries = 3;
        while ($retries--) {
            try {
                $json = $this->rfs->getContents($this->endpoint, $url, false, $options);

                return $this->parseJson($json, $url, $cacheKey);
            } catch (\Exception $e) {
                if ($e instanceof TransportException && 404 === $e->getStatusCode()) {
                    throw $e;
                }

                if ($retries) {
                    usleep(100000);
                    continue;
                }

                if ($cacheKey && $contents = $this->cache->read($cacheKey)) {
                    $this->switchToDegradedMode($e, $url);

                    return Response::fromJson(JsonFile::parseJson($contents, $this->cache->getRoot().$cacheKey));
                }

                throw $e;
            }
        }
    }

    private function fetchFileIfLastModified(string $url, string $cacheKey, string $lastModifiedTime, iterable $headers): Response
    {
        $headers[] = 'If-Modified-Since: '.$lastModifiedTime;
        $options = $this->getOptions($headers);
        $retries = 3;
        while ($retries--) {
            try {
                $json = $this->rfs->getContents($this->endpoint, $url, false, $options);
                if (304 === $this->rfs->findStatusCode($this->rfs->getLastHeaders())) {
                    return new Response('', $this->rfs->getLastHeaders(), 304);
                }

                return $this->parseJson($json, $url, $cacheKey);
            } catch (\Exception $e) {
                if ($e instanceof TransportException && 404 === $e->getStatusCode()) {
                    throw $e;
                }

                if ($retries) {
                    usleep(100000);
                    continue;
                }

                $this->switchToDegradedMode($e, $url);

                return new Response('', [], 304);
            }
        }
    }

    private function parseJson(string $json, string $url, string $cacheKey): Response
    {
        $data = JsonFile::parseJson($json, $url);
        if (!empty($data['warning'])) {
            $this->io->writeError('<warning>Warning from '.$url.': '.$data['warning'].'</warning>');
        }
        if (!empty($data['info'])) {
            $this->io->writeError('<info>Info from '.$url.': '.$data['info'].'</info>');
        }

        $response = new Response($data, $this->rfs->getLastHeaders());
        if ($response->getHeader('last-modified')) {
            $this->cache->write($cacheKey, json_encode($response));
        }

        return $response;
    }

    private function switchToDegradedMode(\Exception $e, string $url): void
    {
        if (!$this->degradedMode) {
            $this->io->writeError('<warning>'.$e->getMessage().'</warning>');
            $this->io->writeError('<warning>'.$url.' could not be fully loaded, package information was loaded from the local cache and may be out of date</warning>');
        }
        $this->degradedMode = true;
    }

    private function getOptions(array $headers): array
    {
        $options = ['http' => ['header' => $headers]];

        if ($this->flexId) {
            $options['http']['header'][] = 'Project: '.$this->flexId;
        }

        if ($this->allowContrib) {
            $options['http']['header'][] = 'Allow-Contrib: 1';
        }

        if (null !== $this->caFile) {
            $options['ssl']['cafile'] = $this->caFile;
        }

        return $options;
    }
}
