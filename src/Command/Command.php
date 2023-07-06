<?php

declare(strict_types=1);

namespace Artemeon\Installer\Command;

use Exception;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

use function Termwind\render;
use function Termwind\terminal;

abstract class Command extends \Symfony\Component\Console\Command\Command
{
    protected InputInterface $input;
    protected OutputInterface $output;

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        return $this->handle();
    }

    protected function arguments(): array
    {
        return $this->input->getArguments();
    }

    protected function argument(string $name): mixed
    {
        return $this->input->getArgument($name);
    }

    protected function options(): array
    {
        return $this->input->getOptions();
    }

    protected function option(string $name): mixed
    {
        return $this->input->getOption($name);
    }

    protected function ask(string $question, string $default = null, bool $hidden = false): mixed
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $questionObject = new Question(' > ', $default);
        $questionObject->setHidden($hidden);

        $this->output->writeln(" <info>$question</info>" . ($default ? " [<comment>$default</comment>]" : '') . ':');

        return $helper->ask($this->input, $this->output, $questionObject);
    }

    protected function secret(string $question, string $default = null): mixed
    {
        return $this->ask($question, $default, true);
    }

    protected function header(): void
    {
        $this->output->writeln('');
        $this->output->writeln('           _____ _____     _____      _               ');
        $this->output->writeln('     /\   / ____|  __ \   / ____|    | |              ');
        $this->output->writeln('    /  \ | |  __| |__) | | (___   ___| |_ _   _ _ __  ');
        $this->output->writeln('   / /\ \| | |_ |  ___/   \___ \ / _ \ __| | | | \'_ \ ');
        $this->output->writeln('  / ____ \ |__| | |       ____) |  __/ |_| |_| | |_) |');
        $this->output->writeln(' /_/    \_\_____|_|      |_____/ \___|\__|\__,_| .__/ ');
        $this->output->writeln('                                               | |    ');
        $this->output->writeln('                                               |_|    ');
        $this->output->writeln('');
    }

    protected function title(string $title): void
    {
        $width = terminal()->width();

        $length = strlen(' ' . $title . ' ');

        $this->output->writeln(' === ' . $title . ' ' . str_repeat('=', $width - $length - 5));
        $this->output->writeln('');
    }

    protected function info(string $message): void
    {
        render(
            <<<HTML
<div class="mb-1 ml-1 px-1">
    <span class="bg-blue-500 text-white px-1 mr-1">INFO</span> $message
</div>
HTML
        );
    }

    protected function error(string $message): void
    {
        render(
            <<<HTML
<div class="mb-1 ml-1 px-1">
    <span class="bg-red-500 text-white px-1 mr-1">ERROR</span> $message
</div>
HTML
        );
    }

    protected function success(string $message): void
    {
        render(
            <<<HTML
<div class="mb-1 ml-1 px-1">
    <span class="bg-green-500 text-white px-1 mr-1">SUCCESS</span> $message
</div>
HTML
        );
    }

    protected function warn(string $message): void
    {
        render(
            <<<HTML
<div class="mb-1 ml-1 px-1">
    <span class="bg-yellow-500 text-gray-900 px-1 mr-1">WARN</span> $message
</div>
HTML
        );
    }

    abstract protected function handle(): int;
}
