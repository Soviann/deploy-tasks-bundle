<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Helper;

use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
                if (Process::ERR === $type) {
                    $output->write(\sprintf('<error>%s</error>', OutputFormatter::escape($buffer)));
                } else {
                    $output->write($buffer);
                }
            });
        } catch (ProcessTimedOutException) {
            $output->writeln(\sprintf(
                '<error>Process timed out after %ss.</error>',
                $process->getTimeout() ?? '0',
            ));

            return TaskResult::FAILURE;
        } catch (ProcessExceptionInterface $e) {
            $output->writeln(\sprintf('<error>Process error: %s</error>', $e->getMessage()));

            return TaskResult::FAILURE;
        }

        if (0 !== $exitCode) {
            $output->writeln(\sprintf('<error>Process exited with code %d.</error>', $exitCode));

            return TaskResult::FAILURE;
        }

        return TaskResult::SUCCESS;
    }

    protected function runProcessWithTimeout(
        Process $process,
        int $seconds,
        OutputInterface $output,
    ): TaskResult {
        $process->setTimeout($seconds);

        return $this->runProcess($process, $output);
    }
}
