<?php

declare(strict_types=1);

namespace Artemeon\Installer\Service;

use Artemeon\Console\Styles\ArtemeonStyle;
use Symfony\Component\Process\Process;

class Frontend
{
    public static function build(ArtemeonStyle $output, string $directory): void
    {
        $detectPnpm = new Process(['pnpm', '--version']);
        $detectPnpm->run();
        if ($detectPnpm->isSuccessful()) {
            $output->info('Installing front-end dependencies ...');
            $pnpmInstall = new Process(['pnpm', 'install'], $directory, timeout: 3600);
            $pnpmInstall->run();
            if (!$pnpmInstall->isSuccessful()) {
                $output->error('An error occurred while installing dependencies.');
                $output->write($pnpmInstall->getErrorOutput());
            } else {
                if ($output->isVerbose()) {
                    $output->success('Dependencies installed.');
                }

                $output->info('Building front-end assets ...');
                $pnpmRunDev = new Process(['pnpm', 'dev'], $directory, timeout: 3600);
                $pnpmRunDev->run();
                if (!$pnpmRunDev->isSuccessful()) {
                    $output->error('An error occurred while building the front-end assets.');
                    $output->write($pnpmRunDev->getErrorOutput());
                } elseif ($output->isVerbose()) {
                    $output->success('Front-end assets built.');
                }
            }
        }
    }
}
