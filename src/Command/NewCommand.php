<?php

declare(strict_types=1);

namespace Artemeon\Installer\Command;

use Artemeon\Console\Command;
use Artemeon\Installer\Service\Frontend;
use Artemeon\Installer\Service\Header;
use Artemeon\Installer\Service\ProjectMatcher;
use Artemeon\Installer\Service\Valet;
use Symfony\Component\Process\Process;
use Throwable;

class NewCommand extends Command
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
        if ($currentDirectory === false) {
            return self::FAILURE;
        }

        $directory = $this->argument('name');
        if (!is_string($directory)) {
            return self::INVALID;
        }

        $this->directory = $directory;
        if (str_contains($directory, '/')) {
            $this->error('Directory must not contain slashes.');

            return self::INVALID;
        }

        $absoluteDirectory = getcwd() . DIRECTORY_SEPARATOR . $directory;

        $branch = $this->option('branch');

        if (is_dir($absoluteDirectory)) {
            $this->error(sprintf('Directory "%s" already exists.', $directory));

            return self::FAILURE;
        }

        Header::print($this->output);

        $this->info(sprintf('Cloning repository into "%s" ...', $directory));
        $parts = array_values(array_filter(['git', 'clone', $branch ? '-b' : null, $branch ?: null, 'https://github.com/artemeon/core-ng.git', $directory]));
        $cloneProcess = new Process($parts, timeout: 3600);
        $cloneProcess->run();
        if (!$cloneProcess->isSuccessful()) {
            $this->error('An error occurred while cloning the repository.');
            $this->output->write($cloneProcess->getErrorOutput());

            return self::FAILURE;
        }

        if ($this->output->isVerbose()) {
            $this->success(sprintf('Repository cloned into "%s".', $directory));
        }

        $project = null;
        if ($this->option('project')) {
            $paths = glob($absoluteDirectory . DIRECTORY_SEPARATOR . 'apps/*', GLOB_ONLYDIR);
            if ($paths === false) {
                return self::FAILURE;
            }

            $projects = array_map(static fn (string $path): string => basename($path), $paths);

            if ($projects !== []) {
                $closestMatch = ProjectMatcher::closest($directory, $projects);
                if ($this->confirm(sprintf('Do you want to check out "%s"?', $closestMatch))) {
                    $project = $closestMatch;
                } else {
                    $project = $this->search('Which project do you want to check out?', static fn (string $query): array => array_filter($projects, static fn (string $project): bool => str_contains(strtolower($project), strtolower($query))));
                }
            }
        }

        if ($project !== null) {
            touch($absoluteDirectory . DIRECTORY_SEPARATOR . '.projectrc');
            file_put_contents($absoluteDirectory . DIRECTORY_SEPARATOR . '.projectrc', $project . PHP_EOL);
        }

        Frontend::build($this->output, $absoluteDirectory);

        $valetAvailable = false;

        try {
            if (Valet::setup($this->output, $directory, $currentDirectory, $absoluteDirectory)) {
                $valetAvailable = true;
                if ($this->output->isVerbose()) {
                    $this->success('Laravel Valet site set up.');
                }

                $this->valetDone = true;
            }
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());
        }

        $composerInstall = new Process(['composer', 'install'], $absoluteDirectory, timeout: 3600);
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

        $this->section('Environment Setup');

        copy($directory . DIRECTORY_SEPARATOR . '.env.example', $directory . DIRECTORY_SEPARATOR . '.env');

        if ($this->input->isInteractive()) {
            if ($webRoot === null) {
                $webRoot = $this->ask('Web root', $defaultWebRoot);
            }

            $envFile = $directory . DIRECTORY_SEPARATOR . '.env';
            $envFileContent = file_get_contents($envFile);
            if ($envFileContent === false) {
                return self::FAILURE;
            }

            $envFileContent = preg_replace('/^AGP_URL=[.*]*$/m', 'AGP_URL=' . $webRoot, $envFileContent);
            file_put_contents($absoluteDirectory . DIRECTORY_SEPARATOR . '.env', $envFileContent);
            $envFileContent = file_get_contents($envFile);
            if ($envFileContent === false) {
                return self::FAILURE;
            }

            $dbHost = $this->ask('Database Host', '127.0.0.1');
            $dbUsername = $this->ask('Database Username', 'root');
            $dbPassword = $this->password('Database Password');
            $dbName = $this->ask('Database Name', $directory);

            $this->newLine();

            $envFileContent = preg_replace('/^AGP_URL=[.*]*$/m', 'AGP_URL=' . $webRoot, $envFileContent);
            if ($envFileContent === null) {
                return self::FAILURE;
            }
            $envFileContent = preg_replace('/^AGP_DB_HOST=[.*]*$/m', 'AGP_DB_HOST=' . $dbHost, $envFileContent);
            if ($envFileContent === null) {
                return self::FAILURE;
            }
            $envFileContent = preg_replace('/^AGP_DB_USER=[.*]*$/m', 'AGP_DB_USER=' . $dbUsername, $envFileContent);
            if ($envFileContent === null) {
                return self::FAILURE;
            }
            $envFileContent = preg_replace('/^AGP_DB_PW=[.*]*$/m', 'AGP_DB_PW=' . $dbPassword, $envFileContent);
            if ($envFileContent === null) {
                return self::FAILURE;
            }
            $envFileContent = preg_replace('/^AGP_DB_DB=[.*]*$/m', 'AGP_DB_DB=' . $dbName, $envFileContent);

            file_put_contents($absoluteDirectory . DIRECTORY_SEPARATOR . '.env', $envFileContent);

            $this->info(sprintf('"%s" updated.', $envFile));

            if ($dbHost !== '' && $dbName !== '' && !str_contains($dbHost, 'dockeruniverse.artemeon.de')) {
                $this->info(sprintf('Installing AGP into database "%s" ...', $dbName));

                $installAgp = Process::fromShellCommandline('php console install', $absoluteDirectory, timeout: 3600);
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

        $this->info('🌐 https://' . $webRoot);

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
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
