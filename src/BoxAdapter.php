<?php

namespace Adnet\Flysystem\Box;

use Adnet\Flysystem\Box\Exceptions\DirectoryDoesNotExist;
use Adnet\Flysystem\Box\Exceptions\FileDoesNotExist;
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
    private const FOLDER = 'folder';
    private const FILE = 'file';

    protected Client $client;

    protected PathPrefixer $prefixer;

    protected MimeTypeDetector $mimeTypeDetector;

    protected string $pathSeparator = '/';

    /**
     * hash mapping paths to their ids and types
     */
    protected ?array $map = null;

    public function __construct(
        Client $client,
        string $prefix = '',
        private readonly int $rootFolderId = 0,
        MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->client = $client;
        $this->prefixer = new PathPrefixer($prefix);
        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector();
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $path): bool
    {
        $location = $this->prefixer->prefixPath($path);

        try {
            $this->getMetadata($location);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        $location = $this->prefixer->prefixPath($path);
        $id = $this->getFolderId($location);

        return $this->client->listItemsInFolder($id);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): void
    {
        $location = $this->prefixer->prefixPath($path);

        try {
            $id = $this->getFileId($location);
            $this->client->delete($id);
        } catch (\Exception $e) {
            throw UnableToDeleteFile::atLocation($location, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(string $path): void
    {
        $location = $this->prefixer->prefixPath($path);

        try {
            $id = $this->getFolderId($location);
            $this->client->deleteFolder($id);
        } catch (\Exception $e) {
            throw UnableToDeleteDirectory::atLocation($location, $e->getMessage(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);

        $rPath = '';
        foreach (explode($this->pathSeparator, $location) as $part) {
            if (! $part) {
                continue;
            }

            $rPath = ($rPath) ? "{$rPath}{$this->pathSeparator}{$part}" : $part;

            if (array_key_exists($rPath, $this->getFoldersMap())) {
                continue;
            }

            $splitPath = explode($this->pathSeparator, $rPath);
            $folderName = array_pop($splitPath);
            $folderPath = implode($this->pathSeparator, $splitPath);
            $parentFolderId = $this->getFolderId($folderPath);

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
        $location = $this->prefixer->prefixPath($path);

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
        $location = $this->prefixer->prefixPath($path);

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

    /**
     * Get all the metadata of a file or directory.
     */
    private function getMetadata(string $path): array
    {
        $location = $this->prefixer->prefixPath($path);

        if ($item = $this->getTypeAndIdByPath($location)) {
            switch ($item['type']) {
                case self::FILE:
                    $response = $this->client->getFileInformation($item['id']);

                    return [
                        'basename' => basename($location),
                        'path' => $location,
                        'size' => $response['size'],
                        'type' => $response['type'],
                        'timestamp' => strtotime($response['modified_at']),
                    ];
                case self::FOLDER:
                    $response = $this->client->getFolderInformation($item['id']);

                    return [
                        'basename' => basename($location),
                        'path' => $location,
                        'type' => $response['type'],
                        'timestamp' => strtotime($response['modified_at']),
                    ];
            }
        }

        throw new UnableToRetrieveMetadata();
    }

    /**
     * @inheritDoc
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, $contents);
        rewind($stream);

        $this->writeStream($path, $stream, $config);
    }

    /**
     * @inheritDoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);
        $splitPath = explode($this->pathSeparator, $location);
        $fileName = array_pop($splitPath);
        $folderPath = implode($this->pathSeparator, $splitPath);

        try {
            $parentFolderId = $this->getFolderId($folderPath);
        } catch (DirectoryDoesNotExist) {
            $this->createDirectory($folderPath, new Config());
            $parentFolderId = $this->getFolderId($folderPath);
        }

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
        $resource = $this->readStream($path);

        $contents = stream_get_contents($resource);
        fclose($resource);
        unset($resource);

        return $contents;
    }

    /**
     * @inheritDoc
     */
    public function readStream(string $path)
    {
        $location = $this->prefixer->prefixPath($path);

        try {
            return $this->client->download($this->getFileId($location));
        } catch (\Exception $e) {
            throw UnableToReadFile::fromLocation($location, $e->getMessage(), $e);
        }
    }

    private function makeFoldersMap(int $folderId = 0, string $path = '', array $map = []): void
    {
        $result = $this->client->listItemsInFolder($folderId);

        foreach ($result['entries'] as $entry) {
            if ($entry['type'] === self::FOLDER) {
                $folderPath = ($path !== '') ? $path . $this->pathSeparator . $entry['name'] : $entry['name'];
                $this->map[$folderPath] = ['id' => $entry['id'], 'name' => $entry['name'], 'path' => $folderPath];
                $this->makeFoldersMap($entry['id'], $folderPath, $map);
            }
        }
    }

    private function getFoldersMap(): array
    {
        // TODO cache map
        if ($this->map === null) {
            $this->map = [$this->prefixer->prefixPath('') => ['id' => $this->rootFolderId, 'type' => self::FOLDER]];
            $this->makeFoldersMap();
        }

        return $this->map;
    }

    private function getFolderId(string $path): int
    {
        if (array_key_exists($path, $this->getFoldersMap())) {
            return $this->getFoldersMap()[$path]['id'];
        }

        throw DirectoryDoesNotExist::forLocation($path);
    }

    private function getFileId(string $path): int
    {
        $splitPath = explode($this->pathSeparator, $path);

        if (empty($splitPath)) {
            throw FileDoesNotExist::forLocation($path);
        }

        $fileName = array_pop($splitPath);
        $folderPath = implode($this->pathSeparator, $splitPath);

        if (empty($folderPath)) {
            $folderPath = $this->pathSeparator;
        }

        $itemsInFolder = $this->client->listItemsInFolder($this->getFolderId($folderPath));

        foreach ($itemsInFolder['entries'] as $entry) {
            if ($entry['type'] === self::FILE && $entry['name'] === $fileName) {
                return (int) $entry['id'];
            }
        }

        throw FileDoesNotExist::forLocation($path);
    }

    private function getTypeAndIdByPath(string $path = '/'): bool|array
    {
        try {
            return ['type' => self::FOLDER, 'id' => $this->getFolderId($path)];
        } catch (DirectoryDoesNotExist) {
            // This is fine, might be a file.
        }

        try {
            return ['type' => self::FILE, 'id' => $this->getFileId($path)];
        } catch (FileDoesNotExist) {
            return false;
        }
    }

    private function getIdByPath(string $path = ''): bool|int
    {
        $result = $this->getTypeAndIdByPath($path);

        return ($result) ? $result['id'] : false;
    }

    /**
     * @inheritDoc
     */
    public function directoryExists(string $path): bool
    {
        return in_array($path, $this->getFoldersMap());
    }
}
