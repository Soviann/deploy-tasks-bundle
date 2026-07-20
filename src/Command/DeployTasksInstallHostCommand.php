<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/** @internal */
#[AsCommand(
    name: 'deploytasks:host:install',
    description: 'Install the host-scope runner, task directory, and .gitignore block into the project.',
)]
final class DeployTasksInstallHostCommand extends Command
{
    private const RUNNER_RELATIVE_PATH = 'bin/deploy-tasks-host.sh';

    private const GITIGNORE_MARKER_START = '###> soviann/deploy-tasks-bundle ###';
    private const GITIGNORE_MARKER_END = '###< soviann/deploy-tasks-bundle ###';
    private const GITIGNORE_BLOCK = <<<'TXT'
        ###> soviann/deploy-tasks-bundle ###
        /.deploy-tasks-host.log
        /.deploy-tasks-host.lock
        /deploy-tasks-host.local.sh
        ###< soviann/deploy-tasks-bundle ###
        TXT;

    private const STATUS_CREATED = 'created';
    private const STATUS_SKIPPED = 'skipped (exists)';
    private const STATUS_OVERWRITTEN = 'overwritten';

    private readonly Filesystem $fs;

    public function __construct(
        /** Configured `host.directory`, project-dir-anchored by the extension. */
        private readonly string $hostDirectory,
        private readonly string $projectDir,
    ) {
        $this->fs = new Filesystem();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite the installed files and rewrite the .gitignore block in place.',
            )
            ->setHelp(
                <<<'EOT'
                    The <info>%command.name%</info> command installs everything host-scope tasks need
                    into the project, in one idempotent step:

                        <info>%command.full_name%</info>

                      - <comment>bin/deploy-tasks-host.sh</comment> — the host runner, copied from the bundle and made executable
                      - the configured <comment>soviann_deploy_tasks.host.directory</comment> (default <comment>deploy/host-tasks/</comment>) — the
                        directory scanned for <comment>*.sh</comment> tasks (kept in git via a <comment>.gitkeep</comment>)
                      - a <comment>###> soviann/deploy-tasks-bundle ###</comment> block in <comment>.gitignore</comment> covering the runner's
                        log, lock, and local-override files

                    Each step reports <comment>created</comment>, <comment>skipped (exists)</comment>, or <comment>overwritten</comment>; existing files are
                    left untouched, and unrelated <comment>.gitignore</comment> content is always preserved.

                    Re-run with <comment>--force</comment> to refresh the runner after a bundle update — it re-copies
                    the runner and rewrites the <comment>.gitignore</comment> block in place:

                        <info>%command.full_name% --force</info>
                    EOT,
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        try {
            $statuses = [
                self::RUNNER_RELATIVE_PATH => $this->installRunner($force),
                $this->gitkeepLabel() => $this->installTaskDirectory($force),
                '.gitignore block' => $this->installGitignoreBlock($force),
            ];
        } catch (IOExceptionInterface $e) {
            $io->error(\sprintf('Installation failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        foreach ($statuses as $artifact => $status) {
            $io->text(\sprintf('  %s: %s', $artifact, $status));
        }

        $counts = \array_count_values($statuses);
        $io->success(\sprintf(
            '%d created, %d skipped, %d overwritten.',
            $counts[self::STATUS_CREATED] ?? 0,
            $counts[self::STATUS_SKIPPED] ?? 0,
            $counts[self::STATUS_OVERWRITTEN] ?? 0,
        ));

        return Command::SUCCESS;
    }

    /**
     * Copies the bundle's runner script into the project and makes it executable.
     */
    private function installRunner(bool $force): string
    {
        $target = $this->projectDir.'/'.self::RUNNER_RELATIVE_PATH;
        $existed = $this->fs->exists($target);

        if ($existed && !$force) {
            return self::STATUS_SKIPPED;
        }

        $this->fs->copy(\dirname(__DIR__, 2).'/bin/deploy-tasks-host.sh.dist', $target, true);
        $this->fs->chmod($target, 0o755);

        return $existed ? self::STATUS_OVERWRITTEN : self::STATUS_CREATED;
    }

    /**
     * Ensures the configured host-task directory exists and stays in git via a .gitkeep.
     */
    private function installTaskDirectory(bool $force): string
    {
        $target = $this->hostDirectory.'/.gitkeep';
        $existed = $this->fs->exists($target);

        if ($existed && !$force) {
            return self::STATUS_SKIPPED;
        }

        $this->fs->mkdir(\dirname($target), 0o755);
        $this->fs->dumpFile($target, '');

        return $existed ? self::STATUS_OVERWRITTEN : self::STATUS_CREATED;
    }

    /**
     * Status-line label for the .gitkeep artifact: project-relative when the
     * configured directory lives under the project, absolute otherwise.
     */
    private function gitkeepLabel(): string
    {
        $target = $this->hostDirectory.'/.gitkeep';

        if (\str_starts_with($target, $this->projectDir.'/')) {
            return \substr($target, \strlen($this->projectDir) + 1);
        }

        return $target;
    }

    /**
     * Ensures .gitignore carries the Flex-style marker block; unrelated content
     * above and below the block is always preserved.
     */
    private function installGitignoreBlock(bool $force): string
    {
        $path = $this->projectDir.'/.gitignore';
        $content = $this->fs->exists($path) ? $this->readFile($path) : '';

        $start = \strpos($content, self::GITIGNORE_MARKER_START);

        if (false !== $start && !$force) {
            return self::STATUS_SKIPPED;
        }

        if (false !== $start) {
            $endMarkerPos = \strpos($content, self::GITIGNORE_MARKER_END, $start);

            if (false !== $endMarkerPos) {
                $blockEnd = $endMarkerPos + \strlen(self::GITIGNORE_MARKER_END);
                $this->fs->dumpFile($path, \substr($content, 0, $start).self::GITIGNORE_BLOCK.\substr($content, $blockEnd));

                return self::STATUS_OVERWRITTEN;
            }
            // Orphan start marker (end marker missing): fall through and append a
            // complete block instead of guessing where the broken one ends.
        }

        $separator = '';

        if ('' !== $content) {
            $separator = \str_ends_with($content, "\n") ? "\n" : "\n\n";
        }

        $this->fs->dumpFile($path, $content.$separator.self::GITIGNORE_BLOCK."\n");

        return false !== $start ? self::STATUS_OVERWRITTEN : self::STATUS_CREATED;
    }

    /**
     * @throws IOException When the file cannot be read
     */
    private function readFile(string $path): string
    {
        $content = @\file_get_contents($path);

        if (false === $content) {
            throw new IOException(\sprintf('Failed to read "%s".', $path), path: $path);
        }

        return $content;
    }
}
