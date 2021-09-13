<?php

require __DIR__ . '/vendor/autoload.php';

use fs\FileSystem;
use fs\FileSystemInterface;
use fs\SambaFs;
use fs\YandexFs;
use Icewind\SMB\Exception\DependencyException;
use Symfony\Component\Process\Process;

function d($variable)
{
    print_r($variable);
    if (is_bool($variable) || is_int($variable) || is_string($variable)) {
        echo "\n";
    }
}


class App
{
    public array $report = [];
    public array $config = [];

    /**
     * @throws Exception
     */
    protected function loadConfig()
    {
        $configPath = __DIR__ . '/configs';
        if (!file_exists("$configPath/config.json")) {
            throw new Exception('Not found config.json');
        }

        $this->config = json_decode(file_get_contents("$configPath/config.json"), true);

        if ($this->config === null) {
            throw new Exception("Error in config file: 'configs/config.json'");
        }

        $this->config['databases'] = $this->loadConfigFromDir("$configPath/db");
        $this->config['directories'] = $this->loadConfigFromDir("$configPath/dir");
    }

    /**
     * @param $path
     * @return array
     * @throws Exception
     */
    public function loadConfigFromDir($path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $files = scandir($path);
        $files = array_filter($files, static function ($name) {
            return preg_match('/^[^_].*\.json$/', $name);
        });
        $configsDb = [];
        foreach ($files as $file) {
            $name = str_replace('.json', '', $file);
            $config = json_decode(file_get_contents($path . '/' . $file), true);
            if ($config === null) {
                throw new Exception("Error in config file: '" . $path . '/' . $file . "'");
            }
            $configsDb[$name] = $config;
        }

        return $configsDb;
    }

    /**
     * @throws Exception
     */
    public function backupDirs()
    {
        foreach ($this->config['directories'] as $name => $dir) {
            try {
                $this->makeBackupDir($name, $dir);
            } catch (Exception $e) {
                $this->log('backup dir', 'error', $e);
            }
        }
    }

    /**
     * @param $name
     * @param $dir
     * @return string
     * @throws Exception
     */
    public function makeBackupDir($name, $dir): string
    {
        $backupsDir = $this->config['tmp_dir'] . '/';

        if (!file_exists($backupsDir)) {
            throw new Exception("Directory $backupsDir for backups does not exist");
        }

        if (!isset($dir['path'])) {
            throw new Exception('The parameter "path" is required');
        }

        $targetDir = $this->config['dir']['root_path'] . '/' . $dir['path'];

        if (!file_exists($targetDir)) {
            throw new Exception('Directory "' . $targetDir . '" does not exist');
        }

        $archiveName = $backupsDir . date('Y-m-d') . '-dir-' . $name . '.tar.gz';

        $cmd = "tar -zcf $archiveName";

        foreach ($this->config['dir']['exclude_dirs'] as $exclude) {
            $cmd .= ' --exclude=' . $exclude;
        }

        if (isset($dir['exclude'])) {
            foreach ($dir['exclude'] as $exclude) {
                $cmd .= ' --exclude=' . $exclude;
            }
        }

        $cmd .= ' -C ' . $this->config['dir']['root_path'];
        $cmd .= ' ' . $dir['path'];

        $process = new Process($cmd);
        $process->mustRun();

        if (filesize($archiveName) < 100) {
            throw new Exception("Backup $archiveName is invalid");
        }

        return $archiveName;
    }

    /**
     * @param FileSystemInterface $fs
     * @throws Exception
     */
    public function copyToBackupServer(FileSystemInterface $fs)
    {
        if (!$fs->isAvailable()) {
            throw new Exception('The backup server is unavailable');
        }

        $files = scandir($this->config['tmp_dir']);
        $files = array_diff($files, ['.', '..']);

        foreach ($files as $file) {
            $source = $this->config['tmp_dir'] . '/' . $file;
            $dest = '/' . $file;
            $fs->copy($source, $dest);

            $fsFileSize = $fs->getSize($file);
            $tmpFileSize = filesize($source);

            if ($fsFileSize !== $tmpFileSize) {
                throw new Exception('Different file sizes!');
            }
        }
    }

    /**
     * @param FileSystemInterface $fs
     * @param $config
     * @throws Exception
     */
    public function cleanupBackups(FileSystemInterface $fs, $config)
    {
        $files = $fs->scandir('/');
        $groups = $this->groupFiles($files);

        if (!isset($config['limit']) || empty($config['limit'])) {
            throw new Exception('The limit is required.');
        }
        if (!is_numeric($config['limit'])) {
            throw new Exception('The limit must be integer.');
        }

        foreach ($groups as $files) {
            if (count($files) > $config['limit']) {
                $remove = array_slice(array_reverse($files), $config['limit']);
                foreach ($remove as $file) {
                    $fs->delete($file['filename']);

                    if ($fs->fileExists($file['filename'])) {
                        throw new Exception("Couldn't delete the file!");
                    }
                }
            }
        }
    }

    public function groupFiles($files): array
    {
        $groups = [];
        foreach ($files as $filename) {
            if (in_array($filename, ['.', '..'])) {
                continue;
            }

            if (!preg_match('/(\d\d\d\d-\d\d-\d\d)-(.*)\.(tar|sql)\.gz/', $filename, $matches)) {
                continue;
            }

            $groupName = $matches[2];
            $time = strtotime($matches[1]);

            $groups[$groupName][] = [
                'filename' => $filename,
                'time' => $time,
                'date' => $matches[1],
            ];
        }

        foreach ($groups as &$group) {
            usort($group, static function ($a, $b) {
                return $a['time'] <=> $b['time'];
            });
        }

        return $groups;
    }

    /**
     * @throws Exception
     */
    public function backupDatabases()
    {
        foreach ($this->config['databases'] as $name => $db) {
            try {
                $backupsDir = $this->config['tmp_dir'] . '/';
                $archiveName = $backupsDir . date('Y-m-d') . '-db-' . $name . '.sql.gz';

                $ignore = '';
                if (isset($db['ignore'])) {
                    foreach ($db['ignore'] as $t) {
                        $ignore .= " --ignore-table={$db['name']}." . $t;
                    }
                }

                $cmd = 'mysqldump';
                $cmd .= " -h {$db['host']}";
                $cmd .= " -u {$db['user']}";
                $cmd .= " -p{$db['pass']}";
                $cmd .= " {$db['name']}";
                $cmd .= " $ignore";
                $cmd .= " --lock-tables=false";
                $cmd .= " | gzip > $archiveName";

                $process = new Process($cmd);
                $process->setTimeout(360000);
                $process->mustRun();

                if (filesize($archiveName) < 1000) {
                    throw new Exception("Backup $archiveName is invalid");
                }

                $this->log('Database "' . $db['name'] . '" was backed up');
            } catch (Exception $e) {
                $this->log('Fail backup database ' . $db['name'], 'error', $e);
            }
        }
    }

    /**
     * @param $mess
     * @param string $type
     * @param null $exception
     * @throws Exception
     */
    public function log($mess, string $type = 'success', $exception = null)
    {
        if (empty($mess)) {
            return;
        }

        if (!in_array($type, ['success', 'error'])) {
            throw new Exception('log: $type must be success or error');
        }

        $tagStyle = 'padding: 6px 10px 3px; margin: 4px 0;';
        switch ($type) {
            case 'success':
                $tagStyle .= ' border-top: 1px solid #ccc; ';
                break;
            case 'error':
                $tagStyle .= ' background: #F55641; color: #fff;';
                break;
        }

        if ($exception) {
            $mess .= '<br><hr><pre>' . print_r($exception, true) . '</pre>';
        }

        $this->report[] = '<div style="' . $tagStyle . '" >' . $mess . '</div>';
    }


    /**
     * @throws DependencyException
     * @throws Exception
     * @throws Exception
     */
    public function run()
    {
        $this->loadConfig();

        $this->backupDirs();
        $this->backupDatabases();

        $sambaSvr = new SambaFs($this->config['storages']['samba_server']);
        $yandexDisk = new YandexFs($this->config['storages']['yandex']);
        $backupSvr = new FileSystem($this->config['storages']['backup_server']);

        $this->copyToBackupServer($backupSvr);
        $this->copyToBackupServer($sambaSvr);
        $this->copyToBackupServer($yandexDisk);

        $this->cleanupBackups($backupSvr, $this->config['storages']['backup_server']);
        $this->cleanupBackups($sambaSvr, $this->config['storages']['samba_server']);
        $this->cleanupBackups($yandexDisk, $this->config['storages']['yandex']);

        $this->cleanTmpDir();
    }

    private function cleanTmpDir()
    {
        $files = scandir($this->config['tmp_dir']);

        $files = array_filter($files, function ($file) {
            return preg_match('/(\d\d\d\d-\d\d-\d\d)-(.*)\.(tar|sql)\.gz/', $file);
        });

        foreach ($files as $file) {
            $path = $this->config['tmp_dir'] . '/' . $file;
            unlink($path);
        }
    }
}

$app = new App();
$app->run();