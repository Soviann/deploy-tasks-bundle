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
use Symfony\Component\Finder\Finder;

/** @internal */
#[AsCommand(name: 'deploytasks:rollup:host', description: 'Mark every pending host-scope deploy task as done.')]
final class DeployTasksRollupHostCommand extends Command
{
    use DestructiveCommandTrait;
    use HostLogManipulationTrait;

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

                You will be prompted for confirmation. To skip the prompt (e.g. in CI), use <comment>--no-interaction</comment>:

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

        // Finder instead of glob(): glob() treats [?* in the *directory path* as
        // pattern metacharacters, silently finding nothing for a project dir like
        // "app[blue]". sortByName() preserves the alphabetical listing.
        $ids = [];
        foreach ((new Finder())->files()->in($this->hostTasksDir)->name('*.sh')->depth(0)->sortByName() as $script) {
            $ids[] = $script->getBasename('.sh');
        }

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

        $pending = \array_values(\array_diff($validIds, $done));

        if ([] === $pending) {
            $io->note('Every host task is already marked as done — nothing to roll up.');

            return Command::SUCCESS;
        }

        if (!$force && !$this->confirmOrAbort($io, \sprintf(
            'This will mark %d host task(s) as done: %s. Continue?',
            \count($pending),
            \implode(', ', \array_map(
                // Defense in depth: pending ids already passed isValidHostTaskId(),
                // which excludes control bytes and formatter tag characters.
                static fn (string $id): string => OutputFormatter::escape(ConsoleSanitizer::sanitize($id)),
                $pending,
            )),
        ))) {
            return Command::FAILURE;
        }

        $lock = $this->acquireHostLock($this->hostLockPath);
        if (null === $lock) {
            $io->warning(\sprintf(CommandMessages::HOST_LOCK_HELD, $this->hostLockPath));

            return DeployTasksRunCommand::EX_TEMPFAIL;
        }

        try {
            $this->appendManyToHostLog($this->hostLogPath, $pending);
        } finally {
            $this->releaseHostLock($lock);
        }

        $io->success(\sprintf('Rolled up: marked %d host task(s) as done.', \count($pending)));

        return Command::SUCCESS;
    }
}
