<?php

namespace League\Flysystem\WebDAV;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Adapter\Polyfill\StreamedReadingTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use LogicException;
use Sabre\DAV\Client;
use Sabre\DAV\Exception;
use Sabre\DAV\Exception\NotFound;
use Sabre\HTTP\HttpException;

class WebDAVAdapter extends AbstractAdapter
{
    use StreamedReadingTrait;
    use StreamedCopyTrait {
        StreamedCopyTrait::copy as streamedCopy;
    }
    use NotSupportingVisibilityTrait;

    private static $metadataFields = [
        '{DAV:}displayname',
        '{DAV:}getcontentlength',
        '{DAV:}getcontenttype',
        '{DAV:}getlastmodified',
        '{DAV:}iscollection',
    ];

    /**
     * @var array
     */
    protected static $resultMap = [
        '{DAV:}getcontentlength' => 'size',
        '{DAV:}getcontenttype' => 'mimetype',
        'content-length' => 'size',
        'content-type' => 'mimetype',
    ];

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var bool
     */
    protected $useStreamedCopy = true;

    /**
     * Constructor.
     *
     * @param Client $client
     * @param string $prefix
     * @param bool $useStreamedCopy
     */
    public function __construct(Client $client, $prefix = null, $useStreamedCopy = true)
    {
        $this->client = $client;
        $this->setPathPrefix($prefix);
        $this->setUseStreamedCopy($useStreamedCopy);
    }

    /**
     * url encode a path
     *
     * @param string $path
     *
     * @return string
     */
    protected function encodePath($path)
    {
        $a = explode('/', $path);
        for ($i=0; $i<count($a); $i++) {
            $a[$i] = rawurlencode($a[$i]);
        }
        return implode('/', $a);
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $location = $this->applyPathPrefix($this->encodePath($path));

        try {
            $result = $this->client->propFind($location, self::$metadataFields);

            if (empty($result)) {
                return false;
            }

            return $this->normalizeObject($result, $path);
        } catch (Exception $e) {
            return false;
        } catch (HttpException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        $location = $this->applyPathPrefix($this->encodePath($path));

        try {
            $response = $this->client->request('GET', $location);

            if ($response['statusCode'] !== 200) {
                return false;
            }

            return array_merge([
                'contents' => $response['body'],
                    // 'timestamp' => strtotime(is_array($response['headers']['last-modified'])
                    // ? current($response['headers']['last-modified'])
                    // : $response['headers']['last-modified']),
                'path' => $path,
            ], Util::map($response['headers'], static::$resultMap));
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        if (!$this->createDir(Util::dirname($path), $config)) {
            return false;
        }

        $location = $this->applyPathPrefix($this->encodePath($path));
        $response = $this->client->request('PUT', $location, $contents);

        if ($response['statusCode'] >= 400) {
            return false;
        }

        $result = compact('path', 'contents');

        if ($config->get('visibility')) {
            throw new LogicException(__CLASS__.' does not support visibility settings.');
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        // die('HERE yo!!!!!!!');
        // echo '<pre>';
        // var_dump($path);
        // echo '</pre>';
        // echo '<pre>';
        // var_dump($resource);
        // echo '</pre>';
        // echo '<pre>';
        // var_dump($config);
        // echo '</pre>';
        // die();
        // echo '<pre>';
        // var_dump($this->write($path, $resource, $config));
        // echo '</pre>';
        // die();
        return $this->write($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->update($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        $location = $this->applyPathPrefix($this->encodePath($path));
        $newLocation = $this->applyPathPrefix($this->encodePath($newpath));

        try {
            $response = $this->client->request('MOVE', '/'.ltrim($location, '/'), null, [
                'Destination' => '/'.ltrim($newLocation, '/'),
            ]);

            if ($response['statusCode'] >= 200 && $response['statusCode'] < 300) {
                return true;
            }
        } catch (NotFound $e) {
            // Would have returned false here, but would be redundant
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath)
    {
        if ($this->useStreamedCopy === true) {
            return $this->streamedCopy($path, $newpath);
        } else {
            return $this->nativeCopy($path, $newpath);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        $location = $this->applyPathPrefix($this->encodePath($path));

        try {
            $response =  $this->client->request('DELETE', $location)['statusCode'];


            return $response >= 200 && $response < 300;
        } catch (NotFound $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($path, Config $config)
    {
        $encodedPath = $this->encodePath($path);
        $path = trim($path, '/');

        $result = compact('path') + ['type' => 'dir'];

        if (Util::normalizeDirname($path) === '' || $this->has($path)) {
            return $result;
        }

        $directories = explode('/', $path);
        if (count($directories) > 1) {
            $parentDirectories = array_splice($directories, 0, count($directories) - 1);
            if (!$this->createDir(implode('/', $parentDirectories), $config)) {
                return false;
            }
        }

        $location = $this->applyPathPrefix($encodedPath);
        $response = $this->client->request('MKCOL', $location);

        if ($response['statusCode'] !== 201) {
            return false;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        return $this->delete($dirname);
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        // Hack due to Drastic's WebDAV directory structure.
        $directory = str_replace('/api/webdav/', '', $directory);

        $location = $this->applyPathPrefix($this->encodePath($directory));
        $response = $this->client->propFind($location . '/', self::$metadataFields, 1);

        // echo '<pre>';
        // var_dump($directory);
        // echo '</pre>';

        array_shift($response);
        $result = [];

        // echo '<pre>';
        // var_dump($response);
        // echo '</pre>';
        // die();

        foreach ($response as $path => $object) {

            // echo '<pre>';
            // var_dump($path);
            // echo '</pre>';
            // die();

            // Hack due to Drastic's WebDAV directory structure.
            $path = str_replace('/api/webdav/', '', $path);

            $path = urldecode($this->removePathPrefix($path));
            $object = $this->normalizeObject($object, $path);
            $result[] = $object;

            // echo '<pre>';
            // var_dump($object);
            // echo '</pre>';
            // echo '<pre>';
            // var_dump($path);
            // echo '</pre>';
            // die();

            if ($recursive && $object['type'] === 'dir' && $object['type'] === 'dir') {
                $result = array_merge($result, $this->listContents($object['path'], true));
            }
        }

        // echo '<pre>';
        // var_dump($result);
        // echo '</pre>';
        // die();

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @return boolean
     */
    public function getUseStreamedCopy()
    {
        return $this->useStreamedCopy;
    }

    /**
     * @param boolean $useStreamedCopy
     */
    public function setUseStreamedCopy($useStreamedCopy)
    {
        $this->useStreamedCopy = (bool)$useStreamedCopy;
    }

    /**
     * Copy a file through WebDav COPY method.
     *
     * @param string $path
     * @param string $newPath
     *
     * @return bool
     */
    protected function nativeCopy($path, $newPath)
    {
        if (!$this->createDir(Util::dirname($newPath), new Config())) {
            return false;
        }

        $location = $this->applyPathPrefix($this->encodePath($path));
        $newLocation = $this->applyPathPrefix($this->encodePath($newPath));

        try {
            $destination = $this->client->getAbsoluteUrl($newLocation);
            $response = $this->client->request('COPY', '/'.ltrim($location, '/'), null, [
                'Destination' => $destination,
            ]);

            if ($response['statusCode'] >= 200 && $response['statusCode'] < 300) {
                return true;
            }
        } catch (NotFound $e) {
            // Would have returned false here, but would be redundant
        }

        return false;
    }

    /**
     * Normalise a WebDAV repsonse object.
     *
     * @param array  $object
     * @param string $path
     *
     * @return array
     */
    protected function normalizeObject(array $object, $path)
    {
        if ($this->isDirectory($object)) {
            return ['type' => 'dir', 'path' => $path];
        }

        $result = Util::map($object, static::$resultMap);

        if (isset($object['{DAV:}getlastmodified'])) {
            $result['timestamp'] = strtotime($object['{DAV:}getlastmodified']);
        }

        $result['type'] = 'file';
        $result['path'] = $path;

        return $result;
    }

    private function isDirectory(array $object)
    {
        // See:
        // https://stackoverflow.com/questions/18382532/obtaining-list-of-folders-from-a-webdav
        // In reference to 'iscollection':
        // "Well, it’s not a "standard" and does not work with all servers."

        // Was:
        // return isset($object['{DAV:}iscollection']) && $object['{DAV:}iscollection'] === '1';

        // Hack:
        return isset($object['{DAV:}getcontentlength']) && $object['{DAV:}getcontentlength'] === '0';
    }
}
