<?php

declare(strict_types=1);

namespace Artemeon\Installer\Service;

use Artemeon\Console\Styles\ArtemeonStyle;

class Header
{
    public static function print(ArtemeonStyle $output): void
    {
        $output->newLine();
        $output->write(<<<OUTPUT
<fg=blue>    __  __  __ </>    _____           _        _ _
<fg=blue>   /_/ /_/ /#/ </>   |_   _|         | |      | | |
<fg=blue>      __  __   </>     | |  _ __  ___| |_ __ _| | | ___ _ __
<fg=blue>     /_/ /_/   </>     | | | '_ \/ __| __/ _` | | |/ _ \ '__|
<fg=blue>        __     </>    _| |_| | | \__ \ || (_| | | |  __/ |
<fg=blue>       /_/     </>   |_____|_| |_|___/\__\__,_|_|_|\___|_|
OUTPUT);
        $output->newLine(3);
    }
}
