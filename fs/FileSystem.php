<?php

namespace fs;


use Exception;

class FileSystem implements FileSystemInterface
{
    private array $options = [];
    private string $path = '';

    /**
     * FileSystem constructor.
     * @param $options
     * @throws Exception
     */
    public function __construct($options)
    {
        if (!isset($options['path']) || empty($options['path'])) {
            throw new Exception('The path is required');
        }

        $this->options = $options;

        if (!file_exists($this->options['path'])) {
            throw new Exception('Directory "' . $this->options['path'] . '" does not exist');
        }
        $this->options = $options;
        $this->path = $options['path'];
    }

    public function isAvailable(): bool
    {
        try {
            $filename = '.avail-' . time();

            if (!touch($this->path . '/' . $filename)) {
                return false;
            }

            if (!unlink($this->path . '/' . $filename)) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function copy($source, $dest): bool
    {
        $dest = $this->path . $dest;
        return copy($source, $dest);
    }

    public function scanDir($directory): array
    {
        $list = scandir($this->path . $directory);

        return array_values(array_filter($list, static function ($i) {
            return !in_array($i, ['.', '..']);
        }));
    }

    public function fileExists($filename): bool
    {
        $path = $this->path . '/' . trim($filename, '/');
        return file_exists($path);
    }

    public function delete($filename): bool
    {
        $filename = $this->path . '/' . trim($filename, '/');
        return unlink($filename);
    }

    public function getSize(string $filename)
    {
        $path = $this->path . '/' . trim($filename, '/');
        return filesize($path);
    }
}