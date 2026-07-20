<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared confirmation guard for commands that destroy stored execution state.
 *
 * Registers the --force opt-out flag and refuses to run non-interactively
 * unless it is set, so a CI pipeline cannot silently wipe state.
 *
 * @internal
 */
trait DestructiveCommandTrait
{
    private function addForceOption(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Confirm destructive run when combined with --no-interaction.',
            )
        ;
    }

    private function isForced(InputInterface $input): bool
    {
        return (bool) $input->getOption('force');
    }

    /**
     * Returns true (and emits the refusal) when the command must abort because it was
     * invoked non-interactively without --force.
     */
    private function refusesNonInteractive(InputInterface $input, OutputInterface $output): bool
    {
        if (!$input->isInteractive() && !$this->isForced($input)) {
            $output->writeln('<error>Refusing to run destructive command non-interactively without --force.</error>');

            return true;
        }

        return false;
    }

    /**
     * Asks the destructive-action confirmation (defaulting to no), emitting the
     * shared abort notice on decline. Returns true when the caller may proceed.
     */
    private function confirmOrAbort(SymfonyStyle $io, string $prompt): bool
    {
        if ($io->confirm($prompt, false)) {
            return true;
        }

        $io->note('Aborted.');

        return false;
    }
}
