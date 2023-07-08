<?php

declare(strict_types=1);

namespace Artemeon\Installer;

use Artemeon\Console\Command;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class NewCommand extends Command
{
    protected string $signature = 'new
                                   {name : The name of the new project.}
                                   {--b|branch= : The branch to checkout.}
                                   {--p|project= : The project to checkout.}';

    protected ?string $description = 'Create a new AGP project';

    private string $directory;
    private string $coreDirectory;
    private string $absoluteDirectory;
    private string $absoluteCoreDirectory;

    public function __invoke(): int
    {
        $directory = $this->directory = $this->argument('name');
        if (str_contains($directory, '/')) {
            $this->error('Directory may not contain slashes.');

            return self::INVALID;
        }
        $coreDirectory = $this->coreDirectory = $directory . DIRECTORY_SEPARATOR . 'core';
        $absoluteDirectory = $this->absoluteDirectory = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $absoluteCoreDirectory = $this->absoluteCoreDirectory = getcwd() . DIRECTORY_SEPARATOR . $coreDirectory;
        $branch = $this->option('branch');

        if (is_dir($absoluteDirectory)) {
            $this->error(sprintf('Directory "%s" already exists.', $directory));

            return self::FAILURE;
        }

        $this->header();

        $project = $this->option('project');
        $isProject = false;
        if ($project) {
            $directoryExistsBefore = is_dir($absoluteDirectory);
            $this->info(sprintf('Cloning "%s" into "%s" ...', $project, $directory));
            $parts = array_values(array_filter(['git', 'clone', $branch ? '-b' : null, $branch ?: null, '--recurse-submodules', 'https://github.com/artemeon/' . $project . '.git', $directory]));
            $cloneProject = new Process($parts, timeout: 3600);
            $cloneProject->run();
            $isProject = $cloneProject->isSuccessful();
            if (!$isProject && !$directoryExistsBefore && is_dir($absoluteDirectory)) {
                rmdir($absoluteDirectory);
            }
            if (!$isProject) {
                $this->error(sprintf('An error occurred while cloning "%s".', $project));

                return self::FAILURE;
            }
            if ($this->output->isVerbose()) {
                $this->success(sprintf('Cloned %s into %s.', $project, $directory));
            }
        }

        if (!$isProject) {
            $this->info(sprintf('Creating directory "%s" ...', $directory));
            if (!mkdir($absoluteDirectory) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $directory));
            }
            if ($this->output->isVerbose()) {
                $this->success(sprintf('Directory "%s" created.', $directory));
            }

            $this->info(sprintf('Cloning repository into "%s" ...', $coreDirectory));
            $parts = array_values(array_filter(['git', 'clone', $branch ? '-b' : null, $branch ?: null, 'https://github.com/artemeon/core-ng.git', 'core']));
            $cloneProcess = new Process($parts, $directory, timeout: 3600);
            $cloneProcess->run();
            if (!$cloneProcess->isSuccessful()) {
                $this->error('An error occurred while cloning the repository.');

                return self::FAILURE;
            }
            if ($this->output->isVerbose()) {
                $this->success(sprintf('Repository cloned into "%s".', $coreDirectory));
            }
        }

        if (!$isProject && is_file($absoluteCoreDirectory . DIRECTORY_SEPARATOR . 'setupproject.php')) {
            $this->info('Setting up project ...');
            $setupProcess = new Process(['php', '-f', 'setupproject.php', 'skip-frontend-build'], $coreDirectory);
            $setupProcess->run();
            if (!$setupProcess->isSuccessful()) {
                $this->error('An error occurred while setting up the project.');

                return self::FAILURE;
            }
            if ($this->output->isVerbose()) {
                $this->success('Project set up.');
            }

            $this->buildFrontend();
        }

        if ($isProject) {
            $this->buildFrontend();
        }

        $valetAvailable = false;

        try {
            if ($this->setupValet()) {
                $valetAvailable = true;
                if ($this->output->isVerbose()) {
                    $this->success('Laravel Valet site set up.');
                }
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }

        if ($isProject) {
            $composerInstall = new Process(['composer', 'install'], $directory . DIRECTORY_SEPARATOR . 'project');
            $composerInstall->run();
            $composerInstalled = $composerInstall->isSuccessful();
            if (!$composerInstalled) {
                $this->error('An error occurred while installing Composer dependencies.');
            } elseif ($this->output->isVerbose()) {
                $this->success('Composer dependencies installed.');
            }
        }

        $envExampleExists = is_file($absoluteDirectory . DIRECTORY_SEPARATOR . '.env.example');
        $envExists = is_file($absoluteDirectory . DIRECTORY_SEPARATOR . '.env');

        $defaultWebRoot = 'dev.artemeon.de/' . $directory;
        if ($valetAvailable) {
            $getValetTld = new Process(['valet', 'tld']);
            $getValetTld->run();
            $valetTld = $getValetTld->getOutput();
            $defaultWebRoot = $directory . '.' . trim($valetTld);
        }

        if ($envExampleExists && !$envExists) {
            $this->section('Final steps');

            (new Process(['cp', '.env.example', '.env'], $directory))->run();

            $envUpdated = false;
            $dbName = null;
            if ($this->input->isInteractive()) {
                if ($valetAvailable) {
                    $webRoot = $defaultWebRoot;
                } else {
                    $webRoot = $this->ask('Web root', $defaultWebRoot);
                }
                $dbHost = $this->ask('Database Host', '127.0.0.1');
                $dbUsername = $this->ask('Database Username', 'root');
                $dbPassword = $this->secret('Database Password');
                $dbName = $this->ask('Database Name', $directory);

                $this->newLine();

                $envFile = $directory . DIRECTORY_SEPARATOR . '.env';
                $envFileContent = file_get_contents($envFile);
                $envFileContent = preg_replace('/^AGP_URL=[.*]*$/m', 'AGP_URL=' . $webRoot, $envFileContent);
                $envFileContent = preg_replace('/^AGP_DB_HOST=[.*]*$/m', 'AGP_DB_HOST=' . $dbHost, $envFileContent);
                $envFileContent = preg_replace('/^AGP_DB_USER=[.*]*$/m', 'AGP_DB_USER=' . $dbUsername, $envFileContent);
                $envFileContent = preg_replace('/^AGP_DB_PW=[.*]*$/m', 'AGP_DB_PW=' . $dbPassword, $envFileContent);
                $envFileContent = preg_replace('/^AGP_DB_DB=[.*]*$/m', 'AGP_DB_DB=' . $dbName, $envFileContent);

                file_put_contents($absoluteDirectory . DIRECTORY_SEPARATOR . '.env', $envFileContent);

                $this->info(sprintf('"%s" updated.', $envFile));
                $envUpdated = true;
            }

            if (!$isProject && $envUpdated && $dbName) {
                $this->info(sprintf('Installing AGP into database "%s" ...', $dbName));

                $installAgp = Process::fromShellCommandline('php console.php install', $directory, timeout: 3600);
                $installAgp->run();
                if (!$installAgp->isSuccessful()) {
                    $this->error('An error occurred while installing the AGP.');
                } elseif ($this->output->isVerbose()) {
                    $this->success(sprintf('AGP installed into database "%s".', $dbName));
                }
            }
        }

        $this->section('Summary');
        $this->success('Done.');

        return self::SUCCESS;
    }

    private function header(): void
    {
        $this->output->writeln('');
        $this->output->writeln('<fg=blue>    __  __  __ </>    _____           _        _ _           ');
        $this->output->writeln('<fg=blue>   /_/ /_/ /#/ </>   |_   _|         | |      | | |          ');
        $this->output->writeln('<fg=blue>      __  __   </>     | |  _ __  ___| |_ __ _| | | ___ _ __ ');
        $this->output->writeln('<fg=blue>     /_/ /_/   </>     | | | \'_ \/ __| __/ _` | | |/ _ \ \'__|');
        $this->output->writeln('<fg=blue>        __     </>    _| |_| | | \__ \ || (_| | | |  __/ |   ');
        $this->output->writeln('<fg=blue>       /_/     </>   |_____|_| |_|___/\__\__,_|_|_|\___|_|   ');
        $this->output->writeln('');
        $this->output->writeln('');
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
            } else {
                if ($this->output->isVerbose()) {
                    $this->success('Dependencies installed.');
                }

                $this->info('Building front-end assets ...');
                $pnpmRunDev = new Process(['pnpm', 'dev'], $buildFilesDirectory);
                $pnpmRunDev->run();
                if (!$pnpmRunDev->isSuccessful()) {
                    $this->error('An error occurred while building the front-end assets.');
                } elseif ($this->output->isVerbose()) {
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

        if (!$detectValet->isSuccessful() || getenv('TESTING') !== false) {
            return false;
        }

        $this->section(trim($detectValet->getOutput()));

        $this->info('Setting up Laravel Valet site ...');

        $linkProcess = new Process(['valet', 'link', '--secure'], $this->directory);
        $linkProcess->run();

        if ($linkProcess->isSuccessful()) {
            if ($this->output->isVerbose()) {
                $this->success($linkProcess->getOutput());
            }
        } else {
            $this->error($linkProcess->getErrorOutput());
        }

        $composerJsonPath = $this->absoluteCoreDirectory . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerJsonPath)) {
            return true;
        }
        $composerJsonContent = file_get_contents($composerJsonPath);
        $composerJson = json_decode($composerJsonContent, false, 512, JSON_THROW_ON_ERROR);
        $phpVersion = (string) $composerJson->config?->platform?->php;
        $parts = explode('.', $phpVersion);
        $minifiedPhpVersion = implode('.', array_slice($parts, 0, 2));
        $prefixedPhpVersion = 'php@' . $minifiedPhpVersion;

        $this->info(sprintf('Isolating site to use %s ...', $prefixedPhpVersion));

        $isolateProcess = new Process(['valet', 'isolate', $prefixedPhpVersion], $this->directory);
        $isolateProcess->run();

        if ($this->output->isVerbose()) {
            $this->success(sprintf('The site is now using %s', $prefixedPhpVersion));
        }

        return true;
    }
}
