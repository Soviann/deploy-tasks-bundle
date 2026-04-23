<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Helper;

use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Optional helper for deploy tasks that shell out to external commands.
 *
 * Streams stdout/stderr to the task's OutputInterface, enforces the Process's
 * own timeout, and maps the outcome to a TaskResult.
 *
 * Requires symfony/process (listed under "suggest" in composer.json).
 */
trait ProcessRunnerTrait
{
    protected function runProcess(Process $process, OutputInterface $output): TaskResult
    {
        try {
            $exitCode = $process->run(static function (string $type, string $buffer) use ($output): void {
                $output->write(Process::ERR === $type ? "<error>{$buffer}</error>" : $buffer);
            });
        } catch (ProcessTimedOutException) {
            $output->writeln(\sprintf(
                '<error>Process timed out after %ss: %s</error>',
                $process->getTimeout() ?? '0',
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
