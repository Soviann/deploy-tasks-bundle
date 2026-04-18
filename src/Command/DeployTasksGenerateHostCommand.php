<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(
    name: 'deploytasks:generate:host',
    description: 'Generate a blank host-scope deploy task shell script.',
)]
final class DeployTasksGenerateHostCommand extends Command
{
    /**
     * @param (\Closure(): \DateTimeImmutable)|null $nowProvider optional clock override for deterministic timestamps in tests
     */
    public function __construct(
        private readonly string $defaultDirectory = 'deploy/host-tasks',
        private readonly ?string $projectDir = null,
        private readonly ?\Closure $nowProvider = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Target directory for the generated shell script.', $this->defaultDirectory)
            ->setHelp(
                <<<'EOT'
                    The <info>%command.name%</info> command generates a blank host-scope deploy task shell script:

                        <info>%command.full_name%</info>

                    This creates a file like <comment>deploy/host-tasks/deploy_task_20260418_143022.sh</comment> with:
                      - A timestamp-prefixed filename (lexicographic sort = chronological)
                      - An executable bit set (0755)
                      - A minimal bash stub with <comment>set -euo pipefail</comment>

                    You can specify a custom target directory with <comment>--dir</comment>:

                        <info>%command.full_name% --dir=deploy/tasks/</info>

                    Host tasks run outside the Symfony container via <info>bin/deploy-tasks-host.sh</info>.
                    For container-scope tasks (PHP classes), see <info>deploytasks:generate:container</info>.
                    EOT,
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $dirInput */
        $dirInput = $input->getOption('dir');
        $dirInput = \rtrim($dirInput, '/').'/';

        if (null !== $this->projectDir) {
            $absoluteDir = \str_starts_with($dirInput, '/') ? $dirInput : $this->projectDir.'/'.$dirInput;
            $dir = $this->normalizePath($absoluteDir).'/';

            if (!\str_starts_with($dir, $this->projectDir)) {
                $io->error(\sprintf('Directory "%s" is outside the project root.', $dirInput));

                return Command::FAILURE;
            }
        } else {
            $dir = $dirInput;
        }

        $now = null !== $this->nowProvider ? ($this->nowProvider)() : new \DateTimeImmutable();
        $filename = 'deploy_task_'.$now->format('Ymd_His').'.sh';
        $filePath = $dir.$filename;

        if (\file_exists($filePath)) {
            $io->error(\sprintf('File already exists: %s', $filePath));

            return Command::FAILURE;
        }

        if (!\is_dir($dir) && !\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir));
        }

        $generatedAt = $now->format(\DateTimeInterface::ATOM);
        $fileContent = <<<BASH
            #!/usr/bin/env bash
            # Host deploy task
            # Generated at {$generatedAt}
            set -euo pipefail

            # TODO: implement

            BASH;

        \file_put_contents($filePath, $fileContent);
        \chmod($filePath, 0755);

        $io->text([
            \sprintf('Generated new host deploy task to "<info>%s</info>"', $filePath),
            '',
            'To run pending host tasks, use <info>bash bin/deploy-tasks-host.sh [APP_ENV]</info>.',
            '',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Resolves `.` and `..` segments without requiring the path to exist.
     */
    private function normalizePath(string $path): string
    {
        $parts = \explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ('.' === $part || '' === $part) {
                continue;
            }

            if ('..' === $part) {
                \array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        return '/'.\implode('/', $normalized);
    }
}
