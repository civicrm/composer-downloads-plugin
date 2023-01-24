<?php

namespace LastCall\DownloadsPlugin\Handler;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\Git;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;

class GitHandler extends BaseHandler
{
    const TMP_PREFIX = '.composer-extra-tmp-';

    public function createSubpackage()
    {
        $pkg = parent::createSubpackage();
        $pkg->setDistType('git');
        return $pkg;
    }

    public function getTrackingFile()
    {
        $file = basename($this->extraFile['id']) . '-' . md5($this->extraFile['id']) . '.json';
        return
            dirname($this->getTargetPath()) .
            DIRECTORY_SEPARATOR . self::DOT_DIR .
            DIRECTORY_SEPARATOR . $file;
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function download(Composer $composer, IOInterface $io) {
        $urlAndVersion = $this->getSubpackage()->getDistUrl();
        $config = $composer->getConfig();
        $wd = $this->getTargetPath();
        $process = new ProcessExecutor($io);
        $cfs = new Filesystem();
        $git = new Git($io, $config, $process, $cfs);
        if (file_exists($wd)) {
          $cfs->removeDirectory($wd);
        }
        // Make the directory recursively.
        mkdir($wd, 0755, TRUE);
        $gitCallable = static function ($urlAndVersion): string {
            $parts = explode('@', $urlAndVersion);
            $url = $parts[0];
            if (count($parts) > 1) {
                $version = $parts[1];
            }
            else {
                $version = 'master';
            }
            return sprintf('git init && git fetch --depth 1 %s %s && git checkout %s',
              ProcessExecutor::escape($url),
              ProcessExecutor::escape($version),
              ProcessExecutor::escape($version)
            );
        };
        $git->runCommand($gitCallable, $urlAndVersion, $wd);
    }

}
