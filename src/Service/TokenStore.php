<?php

declare(strict_types=1);

namespace Artemeon\Installer\Service;

use Artemeon\Console\Styles\ArtemeonStyle;
use RuntimeException;

class TokenStore
{
    public static function getOrAsk(ArtemeonStyle $output): string
    {
        $parts = [__DIR__, '..', '..', '..', 'installer_github_token.txt'];
        $file = implode(DIRECTORY_SEPARATOR, $parts);

        if (is_file($file)) {
            return file_get_contents($file);
        }

        $token = $output->askHidden('GitHub PAT (classic) (with `repo` scope)', function (string $answer) {
            if (!str_starts_with($answer, 'ghp_')) {
                throw new RuntimeException('Invalid GitHub PAT');
            }

            return $answer;
        });

        file_put_contents($file, $token);

        return $token;
    }
}
