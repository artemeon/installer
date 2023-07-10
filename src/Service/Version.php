<?php

declare(strict_types=1);

namespace Artemeon\Installer\Service;

use ahinkle\PackagistLatestVersion\PackagistLatestVersion;
use Composer\InstalledVersions;
use Exception;
use JsonException;

class Version
{
    /**
     * @throws JsonException
     */
    public static function getPackageName(): ?string
    {
        $directory = implode(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', 'composer.json']);
        $packageJson = json_decode(file_get_contents($directory), true, 512, JSON_THROW_ON_ERROR);

        return $packageJson['name'] ?? null;
    }

    /**
     * @throws JsonException
     */
    public static function fetchVersion(): string
    {
        return InstalledVersions::getPrettyVersion(self::getPackageName());
    }

    /**
     * @throws JsonException
     * @throws Exception
     */
    public static function latestVersion(): ?string
    {
        $packagist = new PackagistLatestVersion();

        return $packagist->getLatestRelease(self::getPackageName())['version'] ?? null;
    }

    /**
     * @throws JsonException
     */
    public static function checkForUpdates(): bool
    {
        $currentVersion = self::fetchVersion();
        $latestVersion = self::latestVersion();

        if (!preg_match('/^\d+\.\d+\.\d+$/', $currentVersion)) {
            return false;
        }

        return version_compare($currentVersion, $latestVersion, '<');
    }
}
