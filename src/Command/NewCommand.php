<?php

declare(strict_types=1);

namespace Artemeon\Installer\Command;

use Artemeon\Console\Command;
use Artemeon\Installer\Service\Frontend;
use Artemeon\Installer\Service\GitHub;
use Artemeon\Installer\Service\Header;
use Artemeon\Installer\Service\ProjectMatcher;
use Artemeon\Installer\Service\TokenStore;
use Artemeon\Installer\Service\Valet;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Process\Process;
use Throwable;

class NewCommand extends Command implements SignalableCommandInterface
{
    protected string $signature = 'new
                                   {name : The name of the new project.}
                                   {--b|branch= : The branch to checkout.}
                                   {--p|project : The project to checkout.}';

    protected ?string $description = 'Create a new AGP project';

    protected array $aliases = ['make', 'create'];

    private ?string $directory = null;
    private bool $valetDone = false;

    public function __invoke(): int
    {
        $this->valetDone = false;
        $start = microtime(true);
        $currentDirectory = getcwd();
        $directory = $this->directory = $this->argument('name');
        if (str_contains($directory, '/')) {
            $this->error('Directory may not contain slashes.');

            return self::INVALID;
        }
        $coreDirectory = $directory . DIRECTORY_SEPARATOR . 'core';
        $absoluteDirectory = getcwd() . DIRECTORY_SEPARATOR . $directory;
        $absoluteCoreDirectory = getcwd() . DIRECTORY_SEPARATOR . $coreDirectory;
        $branch = $this->option('branch');

        if (is_dir($absoluteDirectory)) {
            $this->error(sprintf('Directory "%s" already exists.', $directory));

            return self::FAILURE;
        }

        Header::print($this->output);

        $project = null;
        $projects = [];
        if ($this->option('project')) {
            $token = TokenStore::getOrAsk($this->output);
            if ($token) {
                $this->info('Fetching available projects from GitHub ...');

                try {
                    $projects = GitHub::getProjects($token);
                } catch (JsonException) {
                }
            }

            if (count($projects)) {
                $closestMatch = ProjectMatcher::closest($directory, $projects);
                if ($this->confirm(sprintf('Do you want to check out "%s"?', $closestMatch), true)) {
                    $project = $closestMatch;
                } else {
                    $project = $this->choice('Which project do you want to check out?', $projects);
                }
            } else {
                $project = $this->ask('Which project do you want to check out?');
            }
        }
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
                $this->output->write($cloneProject->getErrorOutput());

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
                $this->output->write($cloneProcess->getErrorOutput());

                return self::FAILURE;
            }
            if ($this->output->isVerbose()) {
                $this->success(sprintf('Repository cloned into "%s".', $coreDirectory));
            }
        }

        if (!$isProject && is_file($absoluteCoreDirectory . DIRECTORY_SEPARATOR . 'setupproject.php')) {
            $this->info('Setting up project ...');
            $setupProcess = new Process(['php', '-f', 'setupproject.php', 'skip-frontend-build'], $coreDirectory, timeout: 3600);
            $setupProcess->run();
            if (!$setupProcess->isSuccessful()) {
                $this->error('An error occurred while setting up the project.');
                $this->output->write($setupProcess->getErrorOutput());

                return self::FAILURE;
            }
            if ($this->output->isVerbose()) {
                $this->success('Project set up.');
            }

            Frontend::build($this->output, $coreDirectory);
        }

        if ($isProject) {
            Frontend::build($this->output, $coreDirectory);
        }

        $valetAvailable = false;

        try {
            if (Valet::setup($this->output, $directory, $currentDirectory, $absoluteCoreDirectory)) {
                $valetAvailable = true;
                if ($this->output->isVerbose()) {
                    $this->success('Laravel Valet site set up.');
                }
                $this->valetDone = true;
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }

        if ($isProject) {
            $composerInstall = new Process(['composer', 'install'], $directory . DIRECTORY_SEPARATOR . 'project', timeout: 3600);
            $composerInstall->run();
            $composerInstalled = $composerInstall->isSuccessful();
            if (!$composerInstalled) {
                $this->error('An error occurred while installing Composer dependencies.');
                $this->output->write($composerInstall->getErrorOutput());

                return self::FAILURE;
            }

            if ($this->output->isVerbose()) {
                $this->success('Composer dependencies installed.');
            }
        }

        $envExampleExists = is_file($absoluteDirectory . DIRECTORY_SEPARATOR . '.env.example');
        $envExists = is_file($absoluteDirectory . DIRECTORY_SEPARATOR . '.env');

        $defaultWebRoot = $directory . '.artemeon.de/';
        if ($valetAvailable) {
            $getValetTld = new Process(['valet', 'tld']);
            $getValetTld->run();
            $valetTld = $getValetTld->getOutput();
            $defaultWebRoot = $directory . '.' . trim($valetTld);
        }

        $webRoot = null;
        if ($valetAvailable) {
            $webRoot = $defaultWebRoot;
        }
        $envUpdated = false;
        if ($envExampleExists && !$envExists) {
            $this->section('Environment Setup');

            (new Process(['cp', '.env.example', '.env'], $directory))->run();

            $dbName = null;
            if ($this->input->isInteractive()) {
                if (!$webRoot && !$valetAvailable) {
                    $webRoot = $this->ask('Web root', $defaultWebRoot);
                }

                $envFile = $directory . DIRECTORY_SEPARATOR . '.env';
                $envFileContent = file_get_contents($envFile);
                $envFileContent = preg_replace('/^AGP_URL=[.*]*$/m', 'AGP_URL=' . $webRoot, $envFileContent);
                file_put_contents($absoluteDirectory . DIRECTORY_SEPARATOR . '.env', $envFileContent);
                $envFileContent = file_get_contents($envFile);

                $dbHost = $this->ask('Database Host', '127.0.0.1');
                $dbUsername = $this->ask('Database Username', 'root');
                $dbPassword = $this->secret('Database Password');
                $dbName = $this->ask('Database Name', $directory);

                $this->newLine();

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
                    $this->output->write($installAgp->getErrorOutput());

                    return self::FAILURE;
                }

                if ($this->output->isVerbose()) {
                    $this->success(sprintf('AGP installed into database "%s".', $dbName));
                }
            }
        }

        $this->section('Summary');
        $this->success('Done.');

        $time_elapsed_secs = microtime(true) - $start;

        if ($this->output->isVerbose()) {
            $this->info(sprintf('Finished after %d seconds.', round($time_elapsed_secs, 2)));
        }

        $url = 'https://' . $webRoot;
        $this->info('🌐 ' . $url);
        if (!$isProject && $valetAvailable) {
            $user = $_SERVER['SUDO_USER'] ?? $_SERVER['USER'];
            (new Process(['sudo', '-u', $user, 'open', $url]))->run();
        }

        return self::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return [SIGINT];
    }

    public function handleSignal(int $signal, false | int $previousExitCode = 0): false | int
    {
        if ($signal === SIGINT && $this->directory) {
            $directory = getcwd() . DIRECTORY_SEPARATOR . $this->directory;
            if (is_dir($directory)) {
                $this->info('🧹 Cleaning up the mess ...');
                if ($this->valetDone) {
                    (new Process(['valet', 'unisolate'], $this->directory))->run();
                    (new Process(['valet', 'unsecure'], $this->directory))->run();
                    (new Process(['valet', 'unlink'], $this->directory))->run();
                }
                $this->rrmdir($directory);
                $this->info('✨ You\'re good to go.');
            }
        }

        return 0;
    }

    private function rrmdir(string $directory): void
    {
        if (is_dir($directory)) {
            $objects = scandir($directory);
            foreach ($objects as $object) {
                if ($object !== '.' && $object !== '..') {
                    $path = $directory . DIRECTORY_SEPARATOR . $object;
                    if (is_dir($path) && !is_link($path)) {
                        $this->rrmdir($path);
                    } else {
                        unlink($path);
                    }
                }
            }
            rmdir($directory);
        }
    }
}
