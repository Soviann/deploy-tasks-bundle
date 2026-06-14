<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Helper\PathNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/** @internal */
#[AsCommand(
    name: 'deploytasks:generate:host',
    description: 'Generate a blank deploy task (bash script, runs on the host outside the container).',
)]
final class DeployTasksGenerateHostCommand extends Command
{
    private readonly Filesystem $fs;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $nowProvider optional clock override for deterministic
     *                                                           timestamps in tests
     */
    public function __construct(
        private readonly string $hostDirectory,
        private readonly ?string $projectDir = null,
        private readonly ?\Closure $nowProvider = null,
    ) {
        $this->fs = new Filesystem();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Target directory for the generated shell script.',
                $this->hostDirectory,
            )
            ->setHelp(
                <<<'EOT'
                    The <info>%command.name%</info> command generates a blank host-scope deploy task shell script:

                        <info>%command.full_name%</info>

                    This creates a file like <comment>deploy/host-tasks/deploy_task_20260418_143022.sh</comment> with:
                      - A timestamp-prefixed filename (lexicographic sort = chronological)
                      - An executable bit set (0750)
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
        $userProvidedDir = $input->hasParameterOption('--dir');

        if ($userProvidedDir && \str_starts_with($dirInput, '/')) {
            $io->error('The --dir option must be a relative path.');

            return Command::FAILURE;
        }

        if (!$userProvidedDir && \str_starts_with($dirInput, '/')) {
            // The configured host_directory default is an absolute path injected via DI.
            // Use it directly; apply only the boundary check.
            $resolvedDir = PathNormalizer::normalize($dirInput);

            if (null !== $this->projectDir) {
                $boundary = \rtrim($this->projectDir, '/').'/';

                if (!\str_starts_with($resolvedDir.'/', $boundary)) {
                    throw new \InvalidArgumentException(\sprintf('The --dir option resolves to "%s", which is outside the project directory "%s".', $resolvedDir, $this->projectDir));
                }
            }

            $dir = $resolvedDir.'/';
        } else {
            $dirInput = \rtrim($dirInput, '/').'/';

            $canonical = PathNormalizer::normalize($dirInput);

            if (\str_starts_with($canonical, '..') || 1 !== \preg_match('#^[A-Za-z0-9/_\-]+$#', $canonical)) {
                $io->error(\sprintf(
                    'Invalid --dir value "%s": must be a relative path using only letters, digits, slash, underscore, dash, and must not traverse above its starting point.',
                    $dirInput,
                ));

                return Command::FAILURE;
            }

            if (null !== $this->projectDir) {
                $resolvedDir = PathNormalizer::normalize($this->projectDir.'/'.$dirInput);
                $boundary = \rtrim($this->projectDir, '/').'/';

                if (!\str_starts_with($resolvedDir.'/', $boundary)) {
                    throw new \InvalidArgumentException(\sprintf('The --dir option resolves to "%s", which is outside the project directory "%s".', $resolvedDir, $this->projectDir));
                }

                $dir = $resolvedDir.'/';
            } else {
                $dir = $dirInput;
            }
        }

        $now = null !== $this->nowProvider ? ($this->nowProvider)() : new \DateTimeImmutable();
        $filename = 'deploy_task_'.$now->format('Ymd_His').'.sh';
        $filePath = $dir.$filename;

        if ($this->fs->exists($filePath)) {
            $io->error(\sprintf('File already exists: %s', $filePath));

            return Command::FAILURE;
        }

        $this->fs->mkdir($dir, 0755);

        $generatedAt = $now->format(\DateTimeInterface::ATOM);
        $fileContent = <<<BASH
            #!/usr/bin/env bash
            # Host deploy task
            # Generated at {$generatedAt}
            #
            # Exit 0 = success, non-zero = failure (host runner collects stdout/stderr).
            # Available env vars: APP_ENV, DATABASE_URL (forwarded from the project's .env).
            # This script is executed by bin/deploy-tasks-host.sh — see docs/creating-tasks.md.
            set -euo pipefail
            IFS=\$'\\n\\t'

            # TODO: implement

            BASH;

        $this->fs->dumpFile($filePath, $fileContent);
        $this->fs->chmod($filePath, 0750);

        $displayPath = PathNormalizer::displayPath($filePath);

        $io->text([
            \sprintf('Generated new host deploy task to "<info>%s</info>"', $displayPath),
            '',
            'To run pending host tasks, use <info>bash bin/deploy-tasks-host.sh [APP_ENV]</info>.',
            '',
        ]);

        if (null !== $this->projectDir && !\is_file($this->projectDir.'/bin/deploy-tasks-host.sh')) {
            $io->warning(\sprintf(
                'Host runner not found at %s/bin/deploy-tasks-host.sh. Copy vendor/soviann/deploy-tasks-bundle/bin/deploy-tasks-host.sh.dist into bin/ and make it executable (chmod +x) to run host tasks.',
                $this->projectDir,
            ));
        }

        return Command::SUCCESS;
    }
}
