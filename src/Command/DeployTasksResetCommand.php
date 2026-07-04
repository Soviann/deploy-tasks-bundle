<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:reset', description: 'Reset a deploy task so it will be executed again on next run.')]
final class DeployTasksResetCommand extends Command
{
    use DestructiveCommandTrait;

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'The deploy task ID to reset (e.g. task_20260412143000_seed_categories).',
            )
            ->addOption(
                'group',
                null,
                InputOption::VALUE_REQUIRED,
                'Reset only a specific group slot (default: every slot for this task).',
            )
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command removes the execution record for a deploy task, so it will be treated as pending and executed again on the next <info>deploytasks:run</info>:

                    <info>%command.full_name% task_20260412143000_seed_categories</info>

                By default every slot for the task is reset. Restrict to a single slot with <comment>--group</comment>:

                    <info>%command.full_name% task_20260412143000_seed_categories --group=predeploy</info>

                You will be prompted for confirmation. To skip the prompt (e.g. in CI), use <comment>--no-interaction</comment>:

                    <info>%command.full_name% task_20260412143000_seed_categories --no-interaction</info>

                To see available task IDs and their current state, use <info>deploytasks:status</info>.

                To run only this task, use <info>deploytasks:run --id=<id></info>.
                EOT)
        ;

        $this->addForceOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $id */
        $id = $input->getArgument('id');

        /** @var string|null $group */
        $group = $input->getOption('group');

        if ($this->refusesNonInteractive($input, $output)) {
            return Command::INVALID;
        }

        $force = $this->isForced($input);

        if (!$this->registry->has($id)) {
            $io->error(\sprintf(CommandMessages::UNKNOWN_TASK, $id));

            return Command::INVALID;
        }

        if (null !== $group) {
            $declared = AsDeployTask::groupsOf($this->registry->get($id));

            if (null !== $declared && !\in_array($group, $declared, true)) {
                $io->warning(\sprintf(
                    'Group "%s" is not declared on task "%s" (declared: %s). Proceeding to clean any stale row anyway.',
                    $group,
                    $id,
                    \implode(', ', $declared),
                ));
            }

            if (!$this->storage->has($id, $group)) {
                $io->note(\sprintf(
                    'Task "%s" has no execution record for group "%s" — already pending.',
                    $id,
                    $group,
                ));

                return Command::SUCCESS;
            }

            if (!$force && !$this->confirmOrAbort($io, \sprintf(
                'Reset task "%s" in group "%s"? It will be executed again on next deploytasks:run for that group.',
                $id,
                $group,
            ))) {
                return Command::FAILURE;
            }

            $this->storage->remove($id, $group);

            $io->success(\sprintf(
                'Task "%s" has been reset in group "%s" and will run again on next deploytasks:run --group=%s.',
                $id,
                $group,
                $group,
            ));

            return Command::SUCCESS;
        }

        if ([] === $this->storage->findByTaskId($id)) {
            $io->note(\sprintf('Task "%s" has no execution records — already pending.', $id));

            return Command::SUCCESS;
        }

        if (!$force && !$this->confirmOrAbort($io, \sprintf(
            'Reset task "%s"? All slots will be cleared and the task will run again on next deploytasks:run.',
            $id,
        ))) {
            return Command::FAILURE;
        }

        $this->storage->removeAll($id);

        $io->success(\sprintf(
            'Task "%s" has been reset across all slots and will run again on next deploytasks:run.',
            $id,
        ));

        return Command::SUCCESS;
    }
}
