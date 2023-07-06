<?php

declare(strict_types=1);

namespace Artemeon\Installer\Command;

use JsonException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;

use Throwable;

use function Termwind\terminal;

class NewCommand extends Command
{
    private string $directory;
    private string $coreDirectory;
    private string $absoluteDirectory;
    private string $absoluteCoreDirectory;

    protected function configure(): void
    {
        $this->setName('new')
            ->setDescription('Create a new AGP project')
            ->addArgument('name', InputArgument::OPTIONAL)
            ->addOption('branch', 'b', InputOption::VALUE_REQUIRED, '', null);
    }

    protected function handle(): int
    {
        $directory = $this->directory = $this->argument('name');
        if (str_contains($directory, '/')) {
            throw new RuntimeException('Directory may not contain slashes.');
        }
        $coreDirectory = $this->coreDirectory = $directory . DIRECTORY_SEPARATOR . 'core';
        $absoluteDirectory = $this->absoluteDirectory = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $absoluteCoreDirectory = $this->absoluteCoreDirectory = getcwd() . DIRECTORY_SEPARATOR . $coreDirectory;

        if (is_dir($absoluteDirectory)) {
            $this->error(sprintf('Directory "%s" already exists.', $directory));

            return self::FAILURE;
        }

        terminal()->clear();
        $this->header();

        $this->output->writeln('');

        $this->info(sprintf('Creating directory "%s" ...', $directory));
        if (!mkdir($absoluteDirectory) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }
        $this->success(sprintf('Directory "%s" created.', $directory));

        $this->info(sprintf('Cloning repository into "%s" ...', $coreDirectory));
        $cloneProcess = new Process(['git', 'clone', 'https://github.com/artemeon/core-ng.git', 'core'], $directory);
        $cloneProcess->run();
        if (!$cloneProcess->isSuccessful()) {
            $this->error('An error occurred while cloning the repository.');
            $this->output->write($cloneProcess->getErrorOutput());

            return self::FAILURE;
        }
        $this->success(sprintf('Repository cloned into "%s".', $coreDirectory));

        $branch = $this->option('branch');
        if ($branch) {
            $currentBranchProcess = new Process(['git', 'branch', '--show-current'], $coreDirectory);
            $currentBranchProcess->run();
            $currentBranch = $currentBranchProcess->getOutput();

            $this->info(sprintf('Switching branch from %s to %s ...', $currentBranch, $branch));
            $branchProcess = new Process(['git', 'checkout', $branch], $coreDirectory);
            $branchProcess->run();
            if (!$branchProcess->isSuccessful()) {
                $this->error('An error occurred while switching the branch.');
                $this->output->write($branchProcess->getErrorOutput());

                return self::FAILURE;
            }
            $this->success(sprintf('Branch switched to %s.', $branch));
        }

        if (is_file($absoluteCoreDirectory . DIRECTORY_SEPARATOR . 'setupproject.php')) {
            $this->info('Setting up project ...');
            $setupProcess = new Process(['php', '-f', 'setupproject.php', 'skip-frontend-build'], $coreDirectory);
            $setupProcess->run();
            if (!$setupProcess->isSuccessful()) {
                $this->error('An error occurred while setting up the project.');
                $this->output->write($setupProcess->getErrorOutput());

                return self::FAILURE;
            }
            $this->success('Project set up.');

            $this->buildFrontend();
        }

        $valetAvailable = false;

        try {
            if ($this->setupValet()) {
                $valetAvailable = true;
                $this->success('Laravel Valet site set up.');
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }

        $envExampleExists = is_file($absoluteDirectory . DIRECTORY_SEPARATOR . '.env.example');
        $envExists = is_file($absoluteDirectory . DIRECTORY_SEPARATOR . '.env');

        $defaultWebRoot = 'https://dev.artemeon.de/' . $directory . '/';
        if ($valetAvailable) {
            $getValetTld = new Process(['valet', 'tld']);
            $getValetTld->run();
            $valetTld = $getValetTld->getOutput();
            $defaultWebRoot = 'https://' . $directory . '.' . trim($valetTld) . '/';
        }

        if ($envExampleExists && !$envExists) {
            $this->title('Final steps');

            $webRoot = $this->ask('Web root', $defaultWebRoot);
            $dbHost = $this->ask(' Database Host', '127.0.0.1');
            $dbUsername = $this->ask(' Database Username', 'root');
            $dbPassword = $this->ask(' Database Password', '');
            $dbName = $this->ask(' Database Name', $directory);

            $this->output->writeln('');

            (new Process(['cp', '.env.example', '.env'], $directory))->run();

            $envFile = $directory . DIRECTORY_SEPARATOR . '.env';
            $envFileContent = file_get_contents($envFile);
            $envFileContent = preg_replace('/^AGP_URL=[.*]*$/m', 'AGP_URL=' . $webRoot, $envFileContent);
            $envFileContent = preg_replace('/^AGP_DB_HOST=[.*]*$/m', 'AGP_DB_HOST=' . $dbHost, $envFileContent);
            $envFileContent = preg_replace('/^AGP_DB_USER=[.*]*$/m', 'AGP_DB_USER=' . $dbUsername, $envFileContent);
            $envFileContent = preg_replace('/^AGP_DB_PW=[.*]*$/m', 'AGP_DB_PW=' . $dbPassword, $envFileContent);
            $envFileContent = preg_replace('/^AGP_DB_DB=[.*]*$/m', 'AGP_DB_DB=' . $dbName, $envFileContent);

            file_put_contents($absoluteDirectory . DIRECTORY_SEPARATOR . '.env', $envFileContent);

            $this->success(sprintf('"%s" updated.', $envFile));
        }

        $this->title('Summary');
        $this->success('Done.');

        return self::SUCCESS;
    }

    private function buildFrontend(): void
    {
        $detectPnpm = new Process(['pnpm', '--version']);
        $detectPnpm->run();
        if ($detectPnpm->isSuccessful()) {
            $buildFilesDirectory = $this->coreDirectory . DIRECTORY_SEPARATOR . '_buildfiles';
            $this->info('Installing front-end dependencies ...');
            $pnpmInstall = new Process(['pnpm', 'install'], $buildFilesDirectory);
            $pnpmInstall->run();
            if (!$pnpmInstall->isSuccessful()) {
                $this->error('An error occurred while installing dependencies.');
                $this->output->write($pnpmInstall->getErrorOutput());
            } else {
                $this->success('Dependencies installed.');

                $this->info('Building front-end assets ...');
                $pnpmRunDev = new Process(['pnpm', 'dev'], $buildFilesDirectory);
                $pnpmRunDev->run();
                if (!$pnpmRunDev->isSuccessful()) {
                    $this->error('An error occurred while building the front-end assets.');
                    $this->output->write($pnpmRunDev->getErrorOutput());
                } else {
                    $this->success('Front-end assets built.');
                }
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function setupValet(): bool
    {
        $detectValet = new Process(['valet', '-V']);
        $detectValet->run();

        if (!$detectValet->isSuccessful()) {
            return false;
        }

        $this->title(trim($detectValet->getOutput()));

        $this->info('Setting up Laravel Valet site ...');

        $linkProcess = new Process(['valet', 'link'], $this->directory);
        $linkProcess->run();

        if ($linkProcess->isSuccessful()) {
            $this->success($linkProcess->getOutput());
        } else {
            $this->error($linkProcess->getErrorOutput());
        }

        $this->info('Securing site ...');

        $secureProcess = new Process(['valet', 'secure'], $this->directory);
        $secureProcess->run();

        if ($secureProcess->isSuccessful()) {
            $this->success($secureProcess->getOutput());
        } else {
            $this->error($secureProcess->getErrorOutput());
        }

        $composerJsonPath = $this->absoluteCoreDirectory . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerJsonPath)) {
            return true;
        }
        $composerJsonContent = file_get_contents($composerJsonPath);
        $composerJson = json_decode($composerJsonContent, false, 512, JSON_THROW_ON_ERROR);
        $phpVersion = (string) $composerJson->config?->platform?->php;
        $parts = explode('.', $phpVersion, 2);
        $minifiedPhpVersion = implode('.', $parts);
        $prefixedPhpVersion = 'php@' . $minifiedPhpVersion;

        $this->info(sprintf('Isolating site to use PHP version %s.', $minifiedPhpVersion));

        $isolateProcess = new Process(['valet', 'isolate', $prefixedPhpVersion], $this->directory);
        $isolateProcess->run();

        $this->success(sprintf('The site is now using %s', $prefixedPhpVersion));

        return true;
    }
}
