<?php

namespace Eugktech\FlysystemBox;

use League\Flysystem;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Eugktech\Box\Client;

class BoxAdapter implements Flysystem\FilesystemAdapter
{
    protected Client $client;

    protected PathPrefixer $prefixer;

    protected MimeTypeDetector $mimeTypeDetector;

    protected ?string $pathPrefix = '';

    protected string $pathSeparator = '/';

    /**
     * hash mapping paths to their ids and types
     */
    protected array $map = ['/' => ['id' => '0', 'type' => 'folder']];

    public function __construct(
        Client $client,
        string $prefix = '',
        MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->client = $client;
        $this->prefixer = new PathPrefixer($prefix);
        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector();
        $this->setFoldersMap();
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $path): bool
    {
        $location = $this->applyPathPrefix($path);

        try {
            return (! empty($this->getMetadata($location)));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        $location = $this->applyPathPrefix($path);

        if (false !== ($id = $this->getIdByPath($location))) {
            return $this->client->listItemsInFolder($id);
        }

        return [];
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): void
    {
        $location = $this->applyPathPrefix($path);

        if (false !== ($id = $this->getIdByPath($location))) {
            try {
                $this->client->delete($id);
            } catch (\Exception $e) {
                throw UnableToDeleteFile::atLocation($location, $e->getMessage(), $e);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path): void
    {
        $location = $this->applyPathPrefix($path);

        if (false !== ($id = $this->getIdByPath($location))) {
            try {
                $this->client->deleteFolder($id);
            } catch (\Exception $e) {
                throw UnableToDeleteDirectory::atLocation($location, $e->getMessage(), $e);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        $rPath = '';
        foreach (explode($this->pathSeparator, $location) as $part) {
            if (! $part) {
                continue;
            }

            $rPath = ($rPath) ? "{$rPath}{$this->pathSeparator}{$part}" : $part;

            if (array_key_exists($rPath, $this->map)) {
                continue;
            }

            $splitPath = explode($this->pathSeparator, $rPath);
            $folderName = array_pop($splitPath);
            $folderPath = implode($this->pathSeparator, $splitPath);
            $parentFolderId = $this->getIdByPath($folderPath);

            try {
                $this->client->createFolder($folderName, $parentFolderId);
                return;
            } catch (\Exception $e) {
                throw UnableToCreateDirectory::atLocation($path, $e->getMessage());
            }
        }

        throw UnableToCreateDirectory::atLocation($path, 'Directory exists');
    }

    /**
     * @inheritDoc
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Adapter does not support visibility controls.');
    }

    /**
     * @inheritDoc
     */
    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    /**
     * @inheritDoc
     */
    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes(
            $path,
            null,
            null,
            null,
            $this->mimeTypeDetector->detectMimeTypeFromPath($path)
        );
    }

    /**
     * @inheritDoc
     */
    public function lastModified(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

        try {
            $response = $this->getMetadata($location);
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::lastModified($location, $e->getMessage());
        }

        $timestamp = (isset($response['timestamp'])) ? strtotime($response['timestamp']) : null;

        return new FileAttributes(
            $path,
            null,
            null,
            $timestamp
        );
    }

    /**
     * @inheritDoc
     */
    public function fileSize(string $path): FileAttributes
    {
        $location = $this->applyPathPrefix($path);

        try {
            $response = $this->getMetadata($location);
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::lastModified($location, $e->getMessage());
        }

        return new FileAttributes(
            $path,
            $response['size'] ?? null
        );
    }

    /**
     * @inheritDoc
     */
    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToSetVisibility::atLocation($source, 'Adapter does not support move command.');
    }

    /**
     * @inheritDoc
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToSetVisibility::atLocation($source, 'Adapter does not support copy command.');
    }

    protected function getPathPrefix(): ?string
    {
        return $this->pathPrefix;
    }

    protected function applyPathPrefix(string $path): string
    {
        return $this->getPathPrefix() . ltrim($path, '\\/');
    }

    /**
     * Get all the metadata of a file or directory.
     */
    public function getMetadata(string $path): bool|array
    {
        $location = $this->applyPathPrefix($path);

        if ($item = $this->getTypeAndIdByPath($location)) {
            switch ($item['type']) {
                case 'file':
                    $response = $this->client->getFileInformation($item['id']);

                    return [
                        'basename' => basename($location),
                        'path' => $location,
                        'size' => $response['size'],
                        'type' => $response['type'],
                        'timestamp' => strtotime($response['modified_at']),
                    ];
                case 'folder':
                    $response = $this->client->getFolderInformation($item['id']);

                    return [
                        'basename' => basename($location),
                        'path' => $location,
                        'type' => $response['type'],
                        'timestamp' => strtotime($response['modified_at']),
                    ];
                default:
                    throw new UnableToRetrieveMetadata();
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        $splitPath = explode($this->pathSeparator, $location);

        $fileName = array_pop($splitPath);
        $folderPath = implode($this->pathSeparator, $splitPath);
        $parentFolderId = $this->getIdByPath($folderPath);

        try {
            $this->client->upload($fileName, $parentFolderId, $contents);
        } catch (\Exception $e) {
            throw UnableToWriteFile::atLocation($location, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->applyPathPrefix($path);

        $splitPath = explode($this->pathSeparator, $location);

        $fileName = array_pop($splitPath);
        $folderPath = implode($this->pathSeparator, $splitPath);
        $parentFolderId = $this->getIdByPath($folderPath);

        try {
            $this->client->upload($fileName, $parentFolderId, $contents);
        } catch (\Exception $e) {
            throw UnableToWriteFile::atLocation($location, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        $object = $this->readStream($path);

        $contents = stream_get_contents($object);
        fclose($object);
        unset($object);

        return $contents;
    }

    /**
     * @inheritDoc
     */
    public function readStream(string $path)
    {
        $location = $this->applyPathPrefix($path);

        if (false !== ($id = $this->getIdByPath($location))) {
            try {
                $stream = $this->client->download($id);
            } catch (\Exception $e) {
                throw UnableToReadFile::fromLocation($location, $e->getMessage(), $e);
            }
            return $stream;
        }
    }

    protected function makeFoldersMap(int $folderId = 0, string $path = '', array $map = []): array
    {
        $result = $this->client->listItemsInFolder($folderId);

        foreach ($result['entries'] as $entry) {
            if ($entry['type'] === 'folder') {
                $folderPath = ($path !== '') ? $path . $this->pathSeparator . $entry['name'] : $entry['name'];
                $map[$folderPath] = ['id' => $entry['id'], 'name' => $entry['name'], 'path' => $folderPath];


                $map = array_merge($map, $this->makeFoldersMap($entry['id'], $folderPath, $map));
            }
        }

        return $map;
    }

    public function setFoldersMap(): void
    {
        // TODO: Cache the results
        $this->map = array_merge($this->map, $this->makeFoldersMap());
    }

    protected function getTypeAndIdByPath(string $path = '/'): bool|array
    {
        if (empty($path)) {
            $path = '/';
        }

        if (array_key_exists($path, $this->map)) {
            return ['type' => 'folder', 'id' => $this->map[$path]['id']];
        }

        $splitPath = explode($this->pathSeparator, $path);

        if (empty($splitPath)) {
            return false;
        }

        $fileName = array_pop($splitPath);
        $folderPath = implode($this->pathSeparator, $splitPath);

        if (empty($folderPath)) {
            $folderPath = $this->pathSeparator;
        }

        if (array_key_exists($folderPath, $this->map)) {
            $itemsInFolder = $this->client->listItemsInFolder($this->map[$folderPath]['id']);

            foreach ($itemsInFolder['entries'] as $entry) {
                if ($entry['type'] === 'file' && $entry['name'] === $fileName) {
                    return ['type' => 'file', 'id' => (int)$entry['id']];
                }
            }
        }

        return false;
    }

    protected function getIdByPath(string $path = ''): bool|int
    {
        $result = $this->getTypeAndIdByPath($path);

        return ($result) ? (int)$result['id'] : false;
    }
}
