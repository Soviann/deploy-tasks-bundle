<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:skip', description: 'Mark a deploy task as skipped without executing it.')]
final class DeployTasksSkipCommand extends Command
{
    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The deploy task ID to skip (e.g. task_20260412143000_seed_categories).')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Target a specific group slot (required when the task declares groups).')
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command marks a deploy task as skipped so it will not be executed on future runs:

                    <info>%command.full_name% task_20260412143000_seed_categories</info>

                When the task declares groups, <comment>--group</comment> is required to pick the slot to skip:

                    <info>%command.full_name% task_20260412143000_seed_categories --group=predeploy</info>

                This is useful when a task is no longer relevant or was handled manually.
                A skipped task can be re-enabled with <info>deploytasks:reset</info>.

                To see available task IDs, use <info>deploytasks:status</info>.
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $id */
        $id = $input->getArgument('id');

        /** @var string|null $group */
        $group = $input->getOption('group');

        if (!$this->registry->has($id)) {
            $io->error(\sprintf('Task "%s" is not registered. Run deploytasks:status to see available tasks.', $id));

            return Command::FAILURE;
        }

        $task = $this->registry->get($id);
        $declared = AsDeployTask::groupsOf($task);

        if (null === $declared) {
            if (null !== $group) {
                $io->error(\sprintf('Task "%s" has no groups declared; --group=%s is not valid.', $id, $group));

                return Command::INVALID;
            }

            $slot = null;
        } else {
            if (null === $group) {
                $io->error(\sprintf('Task "%s" has groups declared (%s); specify --group=… to select a slot.', $id, \implode(', ', $declared)));

                return Command::INVALID;
            }

            if (!\in_array($group, $declared, true)) {
                $io->error(\sprintf('Group "%s" is not declared on task "%s" (declared: %s).', $group, $id, \implode(', ', $declared)));

                return Command::INVALID;
            }

            $slot = $group;
        }

        $this->storage->save(new TaskExecution($id, TaskStatus::Skipped, new \DateTimeImmutable(), null, $slot));

        $io->success(null === $slot
            ? \sprintf('Task "%s" marked as skipped.', $id)
            : \sprintf('Task "%s" marked as skipped in group "%s".', $id, $slot));

        return Command::SUCCESS;
    }
}
