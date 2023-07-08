<?php

declare(strict_types=1);

namespace Artemeon\Installer\Tests;

use Artemeon\Installer\NewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class NewCommandTest extends TestCase
{
    public function testItCanScaffoldANewAgpApp()
    {
        $scaffoldDirectoryName = 'my-app';
        $scaffoldDirectory = __DIR__ . '/../' . $scaffoldDirectoryName;

        if (file_exists($scaffoldDirectory)) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("rd /s /q \"$scaffoldDirectory\"");
            } else {
                exec("rm -rf \"$scaffoldDirectory\"");
            }
        }

        $app = new Application('AGP Installer');
        $app->add(new NewCommand());

        $tester = new CommandTester($app->find('new'));

        $statusCode = $tester->execute(['name' => $scaffoldDirectoryName], ['interactive' => false]);

        $this->assertSame(0, $statusCode);
        $this->assertDirectoryExists($scaffoldDirectory . '/core');
        $this->assertDirectoryExists($scaffoldDirectory . '/project');
        $this->assertFileExists($scaffoldDirectory . '/.env');
    }
}
