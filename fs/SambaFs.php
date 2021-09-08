<?php

namespace fs;

use Icewind\SMB\BasicAuth;
use Icewind\SMB\Exception\DependencyException;
use Icewind\SMB\Exception\InvalidTypeException;
use Icewind\SMB\Exception\NotFoundException;
use Icewind\SMB\IShare;
use Icewind\SMB\ServerFactory;

class SambaFs implements FileSystemInterface
{
    protected IShare $share;
    public array $options = [];
    private string $path;

    /**
     * @param $options
     * @throws DependencyException
     */
    public function __construct($options)
    {
        $serverFactory = new ServerFactory();
        $auth = new BasicAuth($options['username'], $options['workgroup'], $options['password']);
        $server = $serverFactory->createServer($options['ip'], $auth);

        $this->share = $server->getShare('web$');
        $this->path = $options['path'];
        $this->options = $options;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @throws NotFoundException
     * @throws InvalidTypeException
     */
    public function copy($source, $dest)
    {
        if ($this->path) {
            $dest =  $this->path . '/' . $dest;
        }

        $this->share->put($source, $dest);
    }

    /**
     * @param string $directory
     * @return array
     * @throws InvalidTypeException
     * @throws NotFoundException
     */
    public function scanDir(string $directory): array
    {
        $files = [];
        $content = $this->share->dir($this->path);

        foreach ($content as $info) {
            $files[] = $info->getName();
        }

        return $files;
    }

    /**
     * @param string $filename
     * @return bool
     */
    public function fileExists(string $filename): bool
    {
        $path = $this->path . '/' . $filename;

        try {
            $this->share->stat($path);
            return true;
        } catch (NotFoundException $e) {
            return false;
        }
    }

/**
 * @throws NotFoundException
     * @throws InvalidTypeException
     */
    public function delete($filename)
    {
        $path = $this->path . '/' . $filename;
        $this->share->del($path);
    }

    /**
     * @param string $filename
     * @return int
     * @throws InvalidTypeException
     * @throws NotFoundException
     */
    public function getSize(string $filename): int
    {
        $filename = trim($filename, '/');

        if (empty($filename)) {
            throw new NotFoundException($filename);
        }

        $content = $this->share->dir($this->path);

        foreach ($content as $info) {
            if ($info->getName() === $filename) {
                return $info->getSize();
            }
        }

        throw new NotFoundException($filename);
    }
}