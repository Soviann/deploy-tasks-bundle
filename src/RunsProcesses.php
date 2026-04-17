<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Optional helper for deploy tasks that shell out to external commands.
 *
 * Streams stdout/stderr to the task's OutputInterface, enforces a per-call
 * timeout at OS level, and maps the outcome to a TaskResult.
 *
 * Requires symfony/process (listed under "suggest" in composer.json).
 */
trait RunsProcesses
{
    /**
     * @param list<string>               $command Array-form command (no shell parsing)
     * @param array<string, string>|null $env     Extra env vars, or null to inherit
     */
    protected function runProcess(
        array $command,
        OutputInterface $output,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?int $timeout = null,
    ): TaskResult {
        $process = new Process($command, $cwd, $env, $input, $timeout);

        try {
            $exitCode = $process->run(static function (string $type, string $buffer) use ($output): void {
                $output->write(Process::ERR === $type ? "<error>{$buffer}</error>" : $buffer);
            });
        } catch (ProcessTimedOutException) {
            $output->writeln(\sprintf(
                '<error>Process timed out after %ds: %s</error>',
                $timeout ?? 0,
                $process->getCommandLine(),
            ));

            return TaskResult::FAILURE;
        } catch (ProcessExceptionInterface $e) {
            $output->writeln(\sprintf('<error>Process error: %s</error>', $e->getMessage()));

            return TaskResult::FAILURE;
        }

        if (0 !== $exitCode) {
            $output->writeln(\sprintf(
                '<error>Process exited with code %d: %s</error>',
                $exitCode,
                $process->getCommandLine(),
            ));

            return TaskResult::FAILURE;
        }

        return TaskResult::SUCCESS;
    }
}
