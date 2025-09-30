<?php

declare(strict_types=1);

namespace Artemeon\Installer\Tests;

use Artemeon\Installer\Command\NewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class NewCommandTest extends TestCase
{
    public function testItCanScaffoldANewAgpApp(): void
    {
        $scaffoldDirectoryName = 'my-app';
        $scaffoldDirectory = __DIR__ . '/../' . $scaffoldDirectoryName;

        if (file_exists($scaffoldDirectory)) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec(sprintf('rd /s /q "%s"', $scaffoldDirectory));
            } else {
                exec(sprintf('rm -rf "%s"', $scaffoldDirectory));
            }
        }

        $app = new Application('AGP Installer');
        $app->add(new NewCommand());

        $tester = new CommandTester($app->find('new'));

        $statusCode = $tester->execute(['name' => $scaffoldDirectoryName], ['interactive' => false]);

        $this->assertSame(0, $statusCode);
        $this->assertFileExists($scaffoldDirectory . '/.env');
    }
}
