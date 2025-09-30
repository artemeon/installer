<?php

declare(strict_types=1);

namespace Artemeon\Installer\Service;

use Composer\InstalledVersions;
use JsonException;
use RuntimeException;

class Version
{
    /**
     * @throws JsonException
     */
    public static function fetchVersion(): ?string
    {
        $packageName = self::getPackageName();
        if ($packageName === null) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($packageName);
    }

    /**
     * @throws JsonException
     */
    private static function getPackageName(): ?string
    {
        $file = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'composer.json';
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $packageJson = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($packageJson)) {
            throw new RuntimeException('Invalid composer.json.');
        }

        $name = $packageJson['name'] ?? null;
        if (!is_string($name)) {
            return null;
        }

        return $name;
    }
}
