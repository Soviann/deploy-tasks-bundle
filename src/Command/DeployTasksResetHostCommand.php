<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:host:reset', description: 'Reset a host-scope deploy task so it will run again on the next host run.')]
final class DeployTasksResetHostCommand extends Command
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
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'The host task ID to reset (script basename without .sh).',
            )
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command removes a host task's completion record, so it will be treated as pending and run again on the next <info>bin/deploy-tasks-host.sh</info>:

                    <info>%command.full_name% deploy_task_20260418_143022</info>

                This is the host-scope equivalent of <info>deploytasks:reset</info> — same completion log the
                <info>bin/deploy-tasks-host.sh</info> runner reads, see <comment>docs/host-tasks.md</comment> for the contract.

                You will be prompted for confirmation. To skip the prompt (e.g. in CI), use <comment>--no-interaction</comment>:

                    <info>%command.full_name% deploy_task_20260418_143022 --no-interaction --force</info>

                If the script exists but has no completion record, the command reports it is already pending and exits successfully without error. An ID matching neither a script nor a completion record is rejected as unknown; a completion record whose script has been deleted is removed anyway, with a warning.
                EOT)
        ;

        $this->addForceOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $id */
        $id = $input->getArgument('id');

        if (!$this->isValidHostTaskId($id)) {
            $io->error(\sprintf(CommandMessages::HOST_TASK_ID_INVALID, ConsoleSanitizer::sanitize($id)));

            return Command::INVALID;
        }

        if ($this->refusesNonInteractive($input, $output)) {
            return Command::INVALID;
        }

        $force = $this->isForced($input);

        if (!\is_dir($this->hostTasksDir)) {
            $io->error(\sprintf(CommandMessages::HOST_DIR_MISSING, $this->hostTasksDir));

            return Command::INVALID;
        }

        $scriptExists = \is_file($this->hostTasksDir.'/'.$id.'.sh');
        $hasLogEntry = $this->hostLogHas($this->hostLogPath, $id);

        if (!$scriptExists && !$hasLogEntry) {
            $io->error(\sprintf('Host task "%s" not found (no %s.sh in %s and no completion record).', $id, $id, $this->hostTasksDir));

            return Command::INVALID;
        }

        if (!$hasLogEntry) {
            $io->note(\sprintf('Host task "%s" has no completion record — already pending.', $id));

            return Command::SUCCESS;
        }

        if (!$scriptExists) {
            $io->warning(\sprintf('No %s.sh in %s — removing the stale completion record anyway.', $id, $this->hostTasksDir));
        }

        if (!$force && !$this->confirmOrAbort($io, \sprintf(
            'Reset host task "%s"? It will run again on the next bin/deploy-tasks-host.sh.',
            $id,
        ))) {
            return Command::FAILURE;
        }

        return $this->withHostLock($this->hostLockPath, $io, function () use ($io, $id): int {
            $this->rewriteHostLogWithout($this->hostLogPath, $id);

            $io->success(\sprintf(
                'Host task "%s" has been reset and will run again on the next bin/deploy-tasks-host.sh.',
                $id,
            ));

            return Command::SUCCESS;
        });
    }
}
