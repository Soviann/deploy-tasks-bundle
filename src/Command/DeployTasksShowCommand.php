<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\Helper\ConsoleSanitizer;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(
    name: 'deploytasks:show',
    description: 'Show metadata and stored execution records for a single deploy task.',
)]
final class DeployTasksShowCommand extends Command
{
    private const DEFAULT_SLOT_LABEL = '(default slot)';

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskStorageInterface $storage,
        private readonly TaskDescriptionResolver $descriptionResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'The deploy task ID to inspect (e.g. task_20260412143000_seed_categories).',
            )
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command displays the full metadata and stored execution records for a single deploy task:

                    <info>%command.full_name% task_20260412143000_seed_categories</info>

                The output includes:
                  - task ID, class FQCN, human description
                  - declared groups (or "default slot only")
                  - every stored execution record (group, status, executed-at timestamp, full error text if the slot failed)

                Related commands:
                  <info>deploytasks:reset <id></info>      — clear stored execution so the task runs again
                  <info>deploytasks:run --id=<id></info>   — run only this task on the next invocation
                  <info>deploytasks:status</info>          — list every registered task and their slot state
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $id */
        $id = $input->getArgument('id');

        if (!$this->registry->has($id)) {
            $io->error(\sprintf(CommandMessages::UNKNOWN_TASK, $id));

            return Command::FAILURE;
        }

        $task = $this->registry->get($id);
        $declared = AsDeployTask::groupsOf($task);
        $groupsLabel = null === $declared ? 'default slot only' : \implode(', ', $declared);

        $io->title(\sprintf('Deploy task: %s', $id));
        $io->definitionList(
            ['ID' => $id],
            ['Class' => $task::class],
            ['Description' => $this->descriptionResolver->resolve($task)],
            ['Declared groups' => $groupsLabel],
        );

        $executions = $this->storage->findByTaskId($id);

        if ([] === $executions) {
            $io->note('No execution records — task is pending on every declared slot.');
        } else {
            $io->section('Execution records');
            foreach ($executions as $execution) {
                $this->renderExecution($io, $execution);
            }
        }

        $io->section('Related commands');
        $io->listing([
            \sprintf('deploytasks:reset %s', $id),
            \sprintf('deploytasks:run --id=%s', $id),
        ]);

        return Command::SUCCESS;
    }

    private function renderExecution(SymfonyStyle $io, TaskExecution $execution): void
    {
        $rows = [
            ['Group' => $execution->group ?? self::DEFAULT_SLOT_LABEL],
            ['Status' => CommandMessages::statusTag($execution->status)],
            ['Executed at' => $execution->executedAt->format('Y-m-d H:i:s')],
        ];

        if (null !== $execution->error) {
            $rows[] = ['Error' => ConsoleSanitizer::sanitize($execution->error)];
        }

        $io->definitionList(...$rows);
    }
}
