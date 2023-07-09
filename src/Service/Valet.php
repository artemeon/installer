<?php

declare(strict_types=1);

namespace Artemeon\Installer\Service;

use Artemeon\Console\Style\ArtemeonStyle;
use Symfony\Component\Process\Process;

class Valet
{
    /**
     * @throws \JsonException
     */
    public static function setup(ArtemeonStyle $output, string $directory, string $currentDirectory, string $absoluteCoreDirectory): bool
    {
        $detectValet = new Process(['valet', '-V']);
        $detectValet->run();
        $version = trim(str_replace('Laravel Valet', '', $detectValet->getOutput()));
        [$majorVersion] = explode('.', $version);

        if (!$detectValet->isSuccessful() || getenv('TESTING') !== false) {
            return false;
        }

        $output->section(trim($detectValet->getOutput()));

        $valetDriversDirectory = $_SERVER['HOME'] . '/.config/valet/Drivers';
        $agpValetDriverDirectory = $valetDriversDirectory . '/agp-valet-driver';
        $driverFileGlob = glob($valetDriversDirectory .'/*/src/AgpValetDriver.php');

        if (!count($driverFileGlob)) {
            $output->info('Cloning AGP Valet Driver ...');
            if ($output->isVerbose()) {
                $output->info($agpValetDriverDirectory);
            }

            $branch = (int) $majorVersion >= 4 ? 'v4' : 'main';
            $cloneAgpValetDriver = new Process(['git', 'clone', '-b', $branch, 'https://github.com/artemeon/agp-valet-driver.git'], $valetDriversDirectory);
            $cloneAgpValetDriver->run();
        }

        $getParkedDirectories = new Process(['valet', 'paths']);
        $getParkedDirectories->run();
        $parkedDirectories = json_decode(trim($getParkedDirectories->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        $isParked = in_array($currentDirectory, $parkedDirectories, true);

        $output->info('Setting up Laravel Valet site ...');
        if (!$isParked) {
            $linkProcess = new Process(['valet', 'link', '--secure'], $directory);
            $linkProcess->run();

            if ($linkProcess->isSuccessful()) {
                if ($output->isVerbose()) {
                    $output->success($linkProcess->getOutput());
                }
            } else {
                $output->error($linkProcess->getErrorOutput());
            }
        } else {
            $secureProcess = new Process(['valet', 'secure'], $directory);
            $secureProcess->run();

            if ($secureProcess->isSuccessful()) {
                if ($output->isVerbose()) {
                    $output->success($secureProcess->getOutput());
                }
            } else {
                $output->error($secureProcess->getErrorOutput());
            }
        }

        $composerJsonPath = $absoluteCoreDirectory . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerJsonPath)) {
            return true;
        }
        $composerJsonContent = file_get_contents($composerJsonPath);
        $composerJson = json_decode($composerJsonContent, false, 512, JSON_THROW_ON_ERROR);
        $phpVersion = (string) $composerJson->config?->platform?->php;
        $parts = explode('.', $phpVersion);
        $minifiedPhpVersion = implode('.', array_slice($parts, 0, 2));
        $prefixedPhpVersion = 'php@' . $minifiedPhpVersion;

        $output->info(sprintf('Isolating site to use %s ...', $prefixedPhpVersion));

        $isolateProcess = new Process(['valet', 'isolate', $prefixedPhpVersion], $directory);
        $isolateProcess->run();

        if ($output->isVerbose()) {
            $output->success(sprintf('The site is now using %s', $prefixedPhpVersion));
        }

        return true;
    }
}
