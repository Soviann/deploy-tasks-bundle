<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:skip:host', description: 'Mark a host-scope deploy task as done without executing it.')]
final class DeployTasksSkipHostCommand extends Command
{
    // Only the confirmation helper is used: skip is reversible (deploytasks:reset:host), so it
    // intentionally proceeds under --no-interaction without requiring --force.
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
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'The host task ID to skip (script basename without .sh).',
            )
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command marks a host-scope deploy task as done in the completion log, without running its script:

                    <info>%command.full_name% deploy_task_20260418_143022</info>

                This is the host-scope equivalent of <info>deploytasks:skip</info> — same completion log the
                <info>bin/deploy-tasks-host.sh</info> runner reads, see <comment>docs/host-tasks.md</comment> for the contract.

                You will be prompted for confirmation. To skip the prompt (e.g. in CI), use <comment>--no-interaction</comment>:

                    <info>%command.full_name% deploy_task_20260418_143022 --no-interaction</info>

                To re-enable a skipped host task, use <info>deploytasks:reset:host</info>.
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $id */
        $id = $input->getArgument('id');

        if (!\is_dir($this->hostTasksDir)) {
            $io->error(\sprintf(CommandMessages::HOST_DIR_MISSING, $this->hostTasksDir));

            return Command::INVALID;
        }

        if (!\is_file($this->hostTasksDir.'/'.$id.'.sh')) {
            $io->error(\sprintf('Host task "%s" not found (no %s.sh in %s).', $id, $id, $this->hostTasksDir));

            return Command::INVALID;
        }

        if ($this->hostLogHas($this->hostLogPath, $id)) {
            $io->note(\sprintf('Host task "%s" is already done/skipped.', $id));

            return Command::SUCCESS;
        }

        if ($input->isInteractive()) {
            if (!$this->confirmOrAbort($io, \sprintf('Skip host task "%s"? This marks it done without executing.', $id))) {
                return Command::FAILURE;
            }
        }

        $lock = $this->acquireHostLock($this->hostLockPath);
        if (null === $lock) {
            $io->warning(\sprintf(CommandMessages::HOST_LOCK_HELD, $this->hostLockPath));

            return DeployTasksRunCommand::EX_TEMPFAIL;
        }

        try {
            $this->appendToHostLog($this->hostLogPath, $id);
        } finally {
            $this->releaseHostLock($lock);
        }

        $io->success(\sprintf('Host task "%s" marked as done.', $id));

        return Command::SUCCESS;
    }
}
