<?php

/*
 * This file is part of Composer Extra Files Plugin.
 *
 * (c) 2017 Last Call Media, Rob Bayliss <rob@lastcallmedia.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace LastCall\ExtraFiles;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use LastCall\ExtraFiles\Handler\BaseHandler;

class Plugin implements PluginInterface, EventSubscriberInterface
{

    /** @var Composer */
    private $composer;
    /** @var IOInterface */
    private $io;

    private $parser;

    public function __construct()
    {
        $this->parser = new ExtraFilesParser();
    }

    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => ['installExtraFiles', 10],
            PackageEvents::POST_PACKAGE_UPDATE => ['updateExtraFiles', 10],
            ScriptEvents::POST_INSTALL_CMD => ['installExtraFilesRoot', 10],
            ScriptEvents::POST_UPDATE_CMD => ['installExtraFilesRoot', 10],
        ];
    }

    public function installExtraFilesRoot(Event $event) {
        $rootPackage = $this->composer->getPackage();
        $this->installUpdateExtras(getcwd(), $rootPackage);

        // Ensure that any other packages are properly reconciled.
        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        $installationManager = $this->composer->getInstallationManager();
        foreach ($localRepo->getCanonicalPackages() as $package) {
            /** @var \Composer\Package\PackageInterface $package */
            if (!empty($package->getExtra()['extra-files'])) {
                $this->installUpdateExtras($installationManager->getInstallPath($package), $package);
            }
        }
    }
    public function installExtraFiles(PackageEvent $event)
    {
        /** @var \Composer\Package\PackageInterface $package */
        $package = $event->getOperation()->getPackage();
        $installationManager = $event->getComposer()->getInstallationManager();
        $this->installUpdateExtras($installationManager->getInstallPath($package), $package);
    }

    public function updateExtraFiles(PackageEvent $event)
    {
        /** @var \Composer\Package\PackageInterface $package */
        $package = $event->getOperation()->getTargetPackage();
        $installationManager = $event->getComposer()->getInstallationManager();
        $this->installUpdateExtras($installationManager->getInstallPath($package), $package);
    }

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @param string $basePath
     * @param PackageInterface $package
     */
    protected function installUpdateExtras($basePath, $package)
    {
        $first = TRUE;
        foreach ($this->parser->parse($package) as $extraFileHandler) {
            /** @var BaseHandler $extraFileHandler */
            $extraFilePkg = $extraFileHandler->getSubpackage();
            $targetPath = $basePath . '/' . $extraFilePkg->getTargetDir();
            $trackingFile = $extraFileHandler->getTrackingFile($basePath, $this->composer);

            if (file_exists($targetPath) && !file_exists($trackingFile)) {
                $this->io->write(sprintf("<info>Extra file <comment>%s</comment> has been locally overriden in <comment>%s</comment>. To reset it, delete and reinstall.</info>", $extraFilePkg->getName(), $extraFilePkg->getTargetDir()), TRUE);
                continue;
            }

            if (file_exists($targetPath) && file_exists($trackingFile)) {
                $meta = @json_decode(file_get_contents($trackingFile), 1);
                if ($meta['url'] === $extraFilePkg->getDistUrl()) {
                    $this->io->write(sprintf("<info>Skip extra file <comment>%s</comment></info>", $extraFilePkg->getName()), TRUE, IOInterface::VERY_VERBOSE);
                    continue;
                }
            }

            if ($first) {
                $this->io->write(sprintf("<info>Download extra files for <comment>%s</comment></info>", $package->getName()));
                $first = FALSE;
            }

            $this->io->write(sprintf("<info>Download extra file <comment>%s</comment></info>", $extraFilePkg->getName()), TRUE, IOInterface::VERBOSE);
            $extraFileHandler->download($this->composer, $this->io, $basePath);

            if (!file_exists(dirname($trackingFile))) {
                mkdir(dirname($trackingFile), 0777, TRUE);
            }
            file_put_contents($trackingFile, json_encode(
                $extraFileHandler->createTrackingData(),
                JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES
            ));
        }
    }

}
