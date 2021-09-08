<?php

namespace fs;

use Exception;
use Yandex\Disk\DiskClient;
use Yandex\Disk\Exception\DiskRequestException;

class YandexFs implements FileSystemInterface
{
    public DiskClient $yandexDisk;
    public array $options = [];
    public string $path = '';

    /**
     * YandexFs constructor.
     * @param $options
     * @throws Exception
     */
    public function __construct($options)
    {
        if (!isset($options['token']) || empty($options['token'])) {
            throw new Exception('The token is required');
        }

        $this->options = $options;
        $this->path = $options['path'];
        $this->yandexDisk = new DiskClient($options['token']);
    }

    public function isAvailable(): bool
    {
        return !empty($this->yandexDisk);
    }

    public function copy($source, $dest)
    {
        $pathInfo = pathinfo($dest);
        $path = $this->path . $pathInfo['dirname'] . '/';
        $path = preg_replace('#/+#', '/', $path);

        $this->yandexDisk->uploadFile($path, [
            'path' => $source,
            'size' => filesize($source),
            'name' => basename($source)
        ]);
    }

    public function scanDir($directory): array
    {
        $directory = trim($this->path . '/' . trim($directory, '/'), '/');
        $list = $this->yandexDisk->directoryContents($directory . '/');
        $list = array_filter(
            $list,
            static function ($i) use ($directory) {
                return $i['displayName'] !== $directory;
            }
        );

        $list = array_map(static function ($i) {
            return trim($i['displayName'], '/');
        }, $list);

        return array_values(array_filter($list));
    }

    /**
     * @throws DiskRequestException
     */
    public function fileExists($filename): bool
    {
        $filename = $this->path . '/' . trim($filename, '/');
        try {
            $this->yandexDisk->getFile($filename);
            return true;
        } catch (DiskRequestException $e) {
            if ($e->getCode() === 404) {
                return false;
            }

            throw $e;
        }
    }

    public function delete($filename): bool
    {
        $filename = $this->path . '/' . trim($filename, '/');
        return $this->yandexDisk->delete($filename);
    }

    /**
     * @param string $filename
     * @return int
     * @throws Exception
     */
    public function getSize(string $filename): int
    {
        $files = $this->yandexDisk->directoryContents($this->path);

        foreach ($files as $file) {
            if ($file['displayName'] === $filename) {
                return (int) $file['contentLength'];
            }
        }

        throw new Exception('Not found');
    }
}