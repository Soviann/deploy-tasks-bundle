<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Psr\Clock\ClockInterface;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Soviann\DeployTasksBundle\Helper\SystemClock;
use Soviann\DeployTasksBundle\Runner\SlotResolver;
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
    // Only the confirmation helper is used: skip is reversible (deploytasks:reset), so it
    // intentionally proceeds under --no-interaction without requiring --force.
    use DestructiveCommandTrait;

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
        /** Override for deterministic time in tests. */
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'The deploy task ID to skip (e.g. task_20260412143000_seed_categories).',
            )
            ->addOption(
                'group',
                null,
                InputOption::VALUE_REQUIRED,
                'Target a specific group slot (default: every declared slot).',
            )
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command marks a deploy task as skipped so it will not be executed on future runs:

                    <info>%command.full_name% task_20260412143000_seed_categories</info>

                When the task declares groups, every declared slot is skipped by default.
                Use <comment>--group</comment> to pick a single slot:

                    <info>%command.full_name% task_20260412143000_seed_categories --group=predeploy</info>

                This is useful when a task is no longer relevant or was handled manually.
                A skipped task can be re-enabled with <info>deploytasks:reset</info>.

                You will be prompted for confirmation. To skip the prompt (e.g. in CI), use <comment>--no-interaction</comment>:

                    <info>%command.full_name% task_20260412143000_seed_categories --no-interaction</info>

                To see available task IDs, use <info>deploytasks:status</info>.

                To run only this task, use <info>deploytasks:run --id=<id></info>.
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
            $io->error(\sprintf(CommandMessages::UNKNOWN_TASK, $id));

            return Command::INVALID;
        }

        $task = $this->registry->get($id);

        try {
            $slots = SlotResolver::resolve($id, $task, null === $group ? [] : [$group]);
        } catch (TaskGroupMismatchException $e) {
            // Mismatch messages embed the raw --group value. error() escapes
            // formatter tags itself, so sanitize-only covers the missing half
            // (control bytes) without double-escaping.
            $io->error(ConsoleSanitizer::sanitize($e->getMessage()));

            return Command::INVALID;
        }

        // Read each slot before writing to it: an existing record (especially a Ran
        // one, i.e. real execution history) must not be silently overwritten by a
        // blind save(). Every slot is confirmed before anything is saved, so
        // declining one prompt leaves all slots untouched — no partial skip.
        if ($input->isInteractive()) {
            foreach ($slots as $slot) {
                $prompt = $this->buildConfirmationPrompt($id, $slot, $this->storage->get($id, $slot));

                if (!$this->confirmOrAbort($io, $prompt)) {
                    return Command::FAILURE;
                }
            }
        }

        $skippedAt = $this->clock->now();

        foreach ($slots as $slot) {
            $this->storage->save(new TaskExecution($id, TaskStatus::Skipped, $skippedAt, null, $slot));
        }

        $groups = \array_values(\array_filter($slots, static fn (?string $slot): bool => null !== $slot));

        $io->success([] === $groups
            ? \sprintf('Task "%s" marked as skipped.', $id)
            : \sprintf(
                'Task "%s" marked as skipped in group%s %s.',
                $id,
                1 === \count($groups) ? '' : 's',
                \implode(', ', \array_map(static fn (string $slot): string => \sprintf('"%s"', $slot), $groups)),
            ));

        return Command::SUCCESS;
    }

    /**
     * Builds the confirmation prompt for one resolved slot (id + group + existing
     * record in, prompt string out) — called once per slot when a bare invocation
     * targets every declared slot.
     */
    private function buildConfirmationPrompt(string $id, ?string $slot, ?TaskExecution $existing): string
    {
        $target = null === $slot ? \sprintf('"%s"', $id) : \sprintf('"%s" in group "%s"', $id, $slot);

        if (null === $existing) {
            return \sprintf('Skip task %s? This marks it done without executing.', $target);
        }

        if (TaskStatus::Ran === $existing->status) {
            return \sprintf(
                'Task %s already ran on %s. Skipping now will overwrite that record and erase its execution history. Continue?',
                $target,
                $existing->executedAt->format('Y-m-d H:i:s'),
            );
        }

        return \sprintf(
            'Task %s already has a "%s" record from %s. Skipping now will overwrite it. Continue?',
            $target,
            $existing->status->value,
            $existing->executedAt->format('Y-m-d H:i:s'),
        );
    }
}
