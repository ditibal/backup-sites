<?php

namespace fs;

interface FileSystemInterface
{
    public function isAvailable(): bool;

    public function copy(string $source, string $dest);

    public function scanDir(string $directory);

    public function fileExists(string $filename);

    public function delete(string $filename);

    public function getSize(string $filename);
}