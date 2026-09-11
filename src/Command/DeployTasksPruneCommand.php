<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Symfony\Component\String\u;

/** @internal */
#[AsCommand(name: 'deploytasks:prune', description: 'Prune orphaned deploy task execution records from storage.')]
final class DeployTasksPruneCommand extends Command
{
    use DestructiveCommandTrait;

    private const DEFAULT_SLOT_LABEL = '—';
    private const ERROR_COLUMN_MAX_WIDTH = 60;

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
    ) {
        parent::__construct();
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('group')) {
            $groups = [];
            foreach ($this->registry->allRegistered() as $task) {
                foreach ((array) AsDeployTask::groupsOf($task) as $group) {
                    $groups[] = $group;
                }
            }
            $suggestions->suggestValues(\array_values(\array_unique($groups)));
        }
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'group',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only prune orphaned records for these group slot(s) (repeatable).',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview which orphaned records would be pruned without deleting anything.',
            )
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command removes execution records from storage that no longer correspond to any registered deploy task in the codebase:

                    <info>%command.full_name%</info>

                To preview which records would be removed without deleting anything, use <comment>--dry-run</comment>:

                    <info>%command.full_name% --dry-run</info>

                Restrict pruning to specific group slot(s) with <comment>--group</comment> (repeatable):

                    <info>%command.full_name% --group=predeploy</info>
                    <info>%command.full_name% --group=predeploy --group=postdeploy</info>

                You will be prompted for confirmation. To skip the prompt (e.g. in CI), use <comment>--no-interaction</comment> with <comment>--force</comment>:

                    <info>%command.full_name% --no-interaction --force</info>
                EOT)
        ;

        $this->addForceOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = true === $input->getOption('dry-run');

        if (!$dryRun && $this->refusesNonInteractive($input, $output)) {
            return Command::INVALID;
        }

        $force = $this->isForced($input);

        /** @var list<string> $groups */
        $groups = (array) $input->getOption('group');

        foreach ($groups as $group) {
            if (1 !== \preg_match(AsDeployTask::GROUP_NAME_PATTERN, $group)) {
                $io->error(\sprintf('Invalid group name "%s": must match %s.', ConsoleSanitizer::sanitize($group), AsDeployTask::GROUP_NAME_PATTERN));

                return Command::INVALID;
            }
        }

        $tasks = $this->registry->allRegistered();
        $orphaned = $this->findOrphanedExecutions($tasks);

        $filtered = [];
        foreach ($orphaned as $execution) {
            if ([] !== $groups && (null === $execution->group || !\in_array($execution->group, $groups, true))) {
                continue;
            }

            $filtered[] = $execution;
        }

        if ([] === $filtered) {
            $io->note('No orphaned task execution records found in storage.');

            return Command::SUCCESS;
        }

        $this->renderOrphanedTable($io, $filtered);

        if ($dryRun) {
            $io->note(1 === \count($filtered)
                ? '[DRY-RUN] 1 orphaned task execution record would be pruned.'
                : \sprintf('[DRY-RUN] %d orphaned task execution records would be pruned.', \count($filtered))
            );

            return Command::SUCCESS;
        }

        $prompt = 1 === \count($filtered)
            ? 'Prune 1 orphaned task execution record from storage?'
            : \sprintf('Prune %d orphaned task execution records from storage?', \count($filtered));

        if (!$force && !$this->confirmOrAbort($io, $prompt)) {
            return Command::FAILURE;
        }

        if ($this->storage instanceof TransactionalStorageInterface) {
            $this->storage->transactional(function () use ($filtered): void {
                foreach ($filtered as $execution) {
                    $this->storage->remove($execution->id, $execution->group);
                }
            });
        } else {
            foreach ($filtered as $execution) {
                $this->storage->remove($execution->id, $execution->group);
            }
        }

        $io->success(1 === \count($filtered)
            ? 'Successfully pruned 1 orphaned task execution record.'
            : \sprintf('Successfully pruned %d orphaned task execution records.', \count($filtered))
        );

        return Command::SUCCESS;
    }

    /**
     * @param array<string, DeployTaskInterface> $tasks
     *
     * @return list<TaskExecution>
     */
    private function findOrphanedExecutions(array $tasks): array
    {
        $activeSlotKeys = [];
        foreach ($tasks as $id => $task) {
            $declared = AsDeployTask::groupsOf($task);
            $slots = null === $declared ? [null] : $declared;

            foreach ($slots as $slot) {
                $activeSlotKeys[TaskExecution::slotKey($id, $slot)] = true;
            }
        }

        $orphaned = [];
        foreach ($this->storage->all() as $execution) {
            $key = TaskExecution::slotKey($execution->id, $execution->group);
            if (!($activeSlotKeys[$key] ?? false)) {
                $orphaned[] = $execution;
            }
        }

        \usort($orphaned, static fn (TaskExecution $a, TaskExecution $b): int => [$a->id, null !== $a->group, $a->group, $a->executedAt] <=> [$b->id, null !== $b->group, $b->group, $b->executedAt]
        );

        return $orphaned;
    }

    /**
     * @param list<TaskExecution> $orphaned
     */
    private function renderOrphanedTable(SymfonyStyle $io, array $orphaned): void
    {
        $io->section('Orphaned tasks');

        $headers = ['ID', 'Group', 'Status', 'Error', 'Executed At', 'Duration'];
        $rows = [];

        foreach ($orphaned as $execution) {
            $status = CommandMessages::statusTag($execution->status);

            $errorCell = TaskStatus::Failed === $execution->status && null !== $execution->error
                ? u(ConsoleSanitizer::sanitizeForFormatter($execution->error))
                    ->truncate(self::ERROR_COLUMN_MAX_WIDTH, '…')
                    ->toString()
                : '';

            $durationCell = null === $execution->durationMs ? '' : CommandMessages::formatDuration($execution->durationMs);

            $rows[] = [
                ConsoleSanitizer::sanitizeForFormatter($execution->id),
                $execution->group ?? self::DEFAULT_SLOT_LABEL,
                $status,
                $errorCell,
                $execution->executedAt->format('Y-m-d H:i:s'),
                $durationCell,
            ];
        }

        $table = $io->createTable();
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
    }
}
