<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Helper;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
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
 * Timeout precedence: when the using class implements DeployTaskInterface and
 * declares `#[AsDeployTask(timeout: N)]` with N > 0, runProcess() sets the
 * Process's hard timeout to N, overriding any timeout already set on the
 * Process instance. `timeout: 0` (or no attribute) means the attribute has no
 * opinion on the hard timeout — the Process's own timeout, if any, is left
 * untouched. Use runProcessWithTimeout() to apply an explicit, different
 * limit — it bypasses attribute resolution entirely.
 *
 * Requires symfony/process (listed under "suggest" in composer.json).
 */
trait ProcessRunnerTrait
{
    protected function runProcess(Process $process, OutputInterface $output): TaskResult
    {
        if ($this instanceof DeployTaskInterface) {
            $attributeTimeout = AsDeployTask::timeoutOf($this);

            if (null !== $attributeTimeout && $attributeTimeout > 0) {
                $process->setTimeout((float) $attributeTimeout);
            }
        }

        return $this->doRunProcess($process, $output);
    }

    protected function runProcessWithTimeout(
        Process $process,
        int $seconds,
        OutputInterface $output,
    ): TaskResult {
        $process->setTimeout($seconds);

        return $this->doRunProcess($process, $output);
    }

    private function doRunProcess(Process $process, OutputInterface $output): TaskResult
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
            $output->writeln(\sprintf(
                '<error>Process error: %s</error>',
                ConsoleSanitizer::sanitize($e->getMessage()),
            ));

            return TaskResult::FAILURE;
        }

        if (0 !== $exitCode) {
            $output->writeln(\sprintf('<error>Process exited with code %d.</error>', $exitCode));

            return TaskResult::FAILURE;
        }

        return TaskResult::SUCCESS;
    }
}
