<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:rollup:host', description: 'Mark every pending host-scope deploy task as done.')]
final class DeployTasksRollupHostCommand extends Command
{
    use DestructiveCommandTrait;
    use HostLogManipulationTrait;

    private const ALL_DONE_MESSAGE = 'Every host task is already marked as done — nothing to roll up.';

    public function __construct(
        /** Directory scanned for host-scope `*.sh` tasks (the host.directory bundle config). */
        private readonly string $hostTasksDir,
        /** Host runner's append-only completion log (bin/deploy-tasks-host.sh's default `.deploy-tasks-host.log`). */
        private readonly string $hostLogPath,
        /** Host runner's flock file (the host.lock_path bundle config) — taken before any log mutation. */
        private readonly string $hostLockPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command appends every pending host-scope task's id to the completion log, marking them all as done without running their scripts:

                    <info>%command.full_name%</info>

                This is the host-scope equivalent of <info>deploytasks:rollup</info> — useful for fresh environments where the current host state already incorporates all task effects.

                You will be prompted for confirmation — a bulk-operation guard: each individual append is reversible via <info>deploytasks:reset:host</info>, but appending every pending task in one pass deserves a stop. To skip the prompt (e.g. in CI), use <comment>--no-interaction</comment>:

                    <info>%command.full_name% --no-interaction --force</info>
                EOT)
        ;

        $this->addForceOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->refusesNonInteractive($input, $output)) {
            return Command::INVALID;
        }

        $force = $this->isForced($input);

        if (!\is_dir($this->hostTasksDir)) {
            $io->error(\sprintf(CommandMessages::HOST_DIR_MISSING, $this->hostTasksDir));

            return Command::INVALID;
        }

        $ids = $this->listHostTaskIds($this->hostTasksDir);

        if ([] === $ids) {
            $io->warning(\sprintf('No host tasks found in "%s" — nothing to roll up.', $this->hostTasksDir));

            return Command::SUCCESS;
        }

        $done = $this->readHostLog($this->hostLogPath);

        // A hostile basename (e.g. one embedding a newline) would corrupt the log's
        // one-id-per-line contract or the terminal, so it never reaches the confirm
        // list below — the runner itself refuses such scripts too.
        $validIds = \array_values(\array_filter($ids, $this->isValidHostTaskId(...)));
        $ignored = \count($ids) - \count($validIds);
        if ($ignored > 0) {
            $io->warning(\sprintf('%d script(s) ignored (invalid id characters).', $ignored));
        }

        $pendingPreview = \array_values(\array_diff($validIds, $done));

        if ([] === $pendingPreview) {
            $io->note(self::ALL_DONE_MESSAGE);

            return Command::SUCCESS;
        }

        if (!$force && !$this->confirmOrAbort($io, \sprintf(
            'This will mark %d host task(s) as done: %s. Continue?',
            \count($pendingPreview),
            \implode(', ', \array_map(
                // Defense in depth: pending ids already passed isValidHostTaskId(),
                // which excludes control bytes and formatter tag characters.
                static fn (string $id): string => OutputFormatter::escape(ConsoleSanitizer::sanitize($id)),
                $pendingPreview,
            )),
        ))) {
            return Command::FAILURE;
        }

        return $this->withHostLock($this->hostLockPath, $io, function () use ($io, $validIds): int {
            // The confirmation list above came from an advisory pre-prompt read:
            // holding the lock through operator think-time would starve a concurrent
            // bin/deploy-tasks-host.sh run into EX_TEMPFAIL. The set that is actually
            // appended — and the count reported — is therefore recomputed under the
            // lock, so a task completed since (skip:host, a host run) is skipped over
            // instead of being appended, and counted, twice.
            $pending = \array_values(\array_diff($validIds, $this->readHostLog($this->hostLogPath)));

            if ([] === $pending) {
                $io->note(self::ALL_DONE_MESSAGE);

                return Command::SUCCESS;
            }

            $this->appendManyToHostLog($this->hostLogPath, $pending);

            $io->success(\sprintf('Rolled up: marked %d host task(s) as done.', \count($pending)));

            return Command::SUCCESS;
        });
    }
}
