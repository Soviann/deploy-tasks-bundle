<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\TaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'deploytasks:reset', description: 'Reset a deploy task so it will be executed again on next run.')]
final class DeployTasksResetCommand extends Command
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
            ->addArgument('id', InputArgument::REQUIRED, 'The deploy task ID to reset (e.g. task_20260412143000_seed_categories).')
            ->setHelp(<<<'EOT'
                The <info>%command.name%</info> command removes the execution record for a deploy task, so it will be treated as pending and executed again on the next <info>deploytasks:run</info>:

                    <info>%command.full_name% task_20260412143000_seed_categories</info>

                You will be prompted for confirmation. To skip the prompt (e.g. in CI), use <comment>--no-interaction</comment>:

                    <info>%command.full_name% task_20260412143000_seed_categories --no-interaction</info>

                To see available task IDs and their current state, use <info>deploytasks:status</info>.
                EOT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $id */
        $id = $input->getArgument('id');

        if (!$this->registry->has($id)) {
            $io->error(\sprintf('Task "%s" is not registered. Run deploytasks:status to see available tasks.', $id));

            return Command::FAILURE;
        }

        if (!$this->storage->has($id)) {
            $io->note(\sprintf('Task "%s" has no execution record — already pending.', $id));

            return Command::SUCCESS;
        }

        if (!$io->confirm(\sprintf('Reset task "%s"? It will be executed again on next deploytasks:run.', $id))) {
            $io->note('Aborted.');

            return Command::SUCCESS;
        }

        $this->storage->remove($id);

        $io->success(\sprintf('Task "%s" has been reset and will run again on next deploytasks:run.', $id));

        return Command::SUCCESS;
    }
}
