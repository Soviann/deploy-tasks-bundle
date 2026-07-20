<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
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
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only reset these group slot(s) (repeatable); without this flag every recorded slot is reset.',
            )
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command removes the execution record for a deploy task, so it will be treated as pending and executed again on the next <info>deploytasks:run</info>:

                    <info>%command.full_name% task_20260412143000_seed_categories</info>

                By default every slot for the task is reset. Restrict to specific slots with <comment>--group</comment> (repeatable):

                    <info>%command.full_name% task_20260412143000_seed_categories --group=predeploy --group=postdeploy</info>

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

        /** @var list<string> $requestedGroups */
        $requestedGroups = \array_values((array) $input->getOption('group'));

        // Deduplicated like RunOptions does for deploytasks:run, so a repeated
        // value (--group=a --group=a) cannot double-name or double-remove a slot.
        $groups = \array_values(\array_unique($requestedGroups));

        foreach ($groups as $group) {
            if (1 !== \preg_match(AsDeployTask::GROUP_NAME_PATTERN, $group)) {
                // Reject the whole command before any storage access — no partial
                // reset: FilesystemStorage would otherwise throw an uncaught
                // InvalidArgumentException from filePath(), while DBAL storage
                // would exit cleanly — same input must behave the same on every
                // backend. The echoed value just failed the pattern, so it is
                // untrusted: strip control bytes before rendering (error()
                // only escapes formatter tags).
                $io->error(\sprintf('Invalid group name "%s": must match %s.', ConsoleSanitizer::sanitize($group), AsDeployTask::GROUP_NAME_PATTERN));

                return Command::INVALID;
            }
        }

        if ($this->refusesNonInteractive($input, $output)) {
            return Command::INVALID;
        }

        $force = $this->isForced($input);

        if (!$this->registry->has($id)) {
            $io->error(\sprintf(CommandMessages::UNKNOWN_TASK, $id));

            return Command::INVALID;
        }

        if ([] !== $groups) {
            $declared = AsDeployTask::groupsOf($this->registry->get($id));

            foreach ($groups as $group) {
                if (null !== $declared && !\in_array($group, $declared, true)) {
                    $io->warning(\sprintf(
                        'Group "%s" is not declared on task "%s" (declared: %s). Proceeding to clean any stale row anyway.',
                        $group,
                        $id,
                        \implode(', ', $declared),
                    ));
                }
            }

            // Partition the requested groups: slots with a stored record get
            // reset, the others are only noted as already pending. Deliberately
            // storage-driven (not SlotResolver): reset must be able to clean a
            // stale row for a group the task no longer declares.
            $toReset = [];

            foreach ($groups as $group) {
                if ($this->storage->has($id, $group)) {
                    $toReset[] = $group;

                    continue;
                }

                $io->note(\sprintf(
                    'Task "%s" has no execution record for group "%s" — already pending.',
                    $id,
                    $group,
                ));
            }

            if ([] === $toReset) {
                return Command::SUCCESS;
            }

            // ONE confirmation naming every slot about to be reset; declining it
            // removes nothing — a partial reset must be impossible.
            if (!$force && !$this->confirmOrAbort($io, $this->groupResetConfirmationPrompt($id, $toReset))) {
                return Command::FAILURE;
            }

            foreach ($toReset as $group) {
                $this->storage->remove($id, $group);
            }

            $io->success(1 === \count($toReset)
                ? \sprintf(
                    'Task "%s" has been reset in group "%s" and will run again on next deploytasks:run --group=%s.',
                    $id,
                    $toReset[0],
                    $toReset[0],
                )
                : \sprintf(
                    'Task "%s" has been reset in groups %s and will run again on next deploytasks:run.',
                    $id,
                    \implode(', ', \array_map(static fn (string $group): string => \sprintf('"%s"', $group), $toReset)),
                ));

            return Command::SUCCESS;
        }

        $recorded = $this->storage->findByTaskId($id);

        if ([] === $recorded) {
            $io->note(\sprintf('Task "%s" has no execution records — already pending.', $id));

            return Command::SUCCESS;
        }

        if (!$force && !$this->confirmOrAbort($io, \sprintf(
            'Reset task "%s"? All recorded slots (%s) will be cleared and the task will run again on next deploytasks:run.',
            $id,
            \implode(', ', $this->recordedSlotLabels($recorded)),
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

    /**
     * Builds the single confirmation for a --group reset, naming every slot
     * about to be cleared so one answer authorizes the whole batch knowingly.
     * Group names are pattern-validated command input (trusted charset), so no
     * sanitizing is needed before the formatter-interpreting confirm() sink.
     *
     * @param non-empty-list<string> $groups
     */
    private function groupResetConfirmationPrompt(string $id, array $groups): string
    {
        if (1 === \count($groups)) {
            return \sprintf(
                'Reset task "%s" in group "%s"? It will be executed again on next deploytasks:run for that group.',
                $id,
                $groups[0],
            );
        }

        return \sprintf(
            'Reset task "%s" in groups %s? They will be executed again on next deploytasks:run for those groups.',
            $id,
            \implode(', ', \array_map(static fn (string $group): string => \sprintf('"%s"', $group), $groups)),
        );
    }

    /**
     * Names the slots whose records a bare reset is about to remove, so the
     * confirmation states exactly what gets destroyed. Storage returns records
     * in backend-specific order — sorted here (default slot first, then group
     * names alphabetically) for a stable prompt. Group names are read back from
     * storage (a stale row may hold anything), so they pass through the
     * sanitizer before reaching the formatter-interpreting confirm() sink.
     *
     * @param list<TaskExecution> $executions
     *
     * @return list<string>
     */
    private function recordedSlotLabels(array $executions): array
    {
        $groups = \array_map(static fn (TaskExecution $execution): ?string => $execution->group, $executions);

        \usort($groups, static fn (?string $a, ?string $b): int => [null !== $a, $a] <=> [null !== $b, $b]);

        return \array_map(
            static fn (?string $group): string => null === $group ? 'default' : ConsoleSanitizer::sanitizeForFormatter($group),
            $groups,
        );
    }
}
