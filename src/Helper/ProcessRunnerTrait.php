<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Helper;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Optional helper for deploy tasks that shell out to external commands.
 *
 * Streams stdout/stderr to the task's OutputInterface, enforces timeouts,
 * and maps the outcome to a TaskResult.
 *
 * Timeout precedence: when the using class implements DeployTaskInterface and
 * declares `#[AsDeployTask(timeout: N)]` with N > 0, runProcess() sets the
 * Process's hard timeout to N if no explicit $timeout argument is passed.
 * `timeout: 0` (or no attribute) leaves the Process's own timeout untouched.
 *
 * Requires symfony/process (listed under "suggest" in composer.json).
 */
trait ProcessRunnerTrait
{
    /**
     * Executes a Process instance, streaming stdout/stderr to $output.
     *
     * @param int|null    $timeout      Hard timeout in seconds; null uses attribute/Process default
     * @param bool        $quiet        When true, suppresses streaming output to $output
     * @param string|null $outputPrefix Prefix prepended to each streamed output line
     *
     * @throws \InvalidArgumentException When $timeout is negative
     */
    protected function runProcess(Process $process, OutputInterface $output, ?int $timeout = null, bool $quiet = false, ?string $outputPrefix = null): TaskResult
    {
        if (null !== $timeout) {
            if ($timeout < 0) {
                throw new \InvalidArgumentException(\sprintf('Invalid timeout %d in runProcess(): must be >= 0.', $timeout));
            }

            $process->setTimeout((float) $timeout);
        } elseif ($this instanceof DeployTaskInterface) {
            $attributeTimeout = AsDeployTask::timeoutOf($this);

            if (null !== $attributeTimeout && $attributeTimeout > 0) {
                $process->setTimeout((float) $attributeTimeout);
            }
        }

        return $this->doRunProcess($process, $output, $quiet, $outputPrefix);
    }

    /**
     * Executes a command specified as a string or array of arguments.
     *
     * @param string|array<string>       $command      Command line string or array of arguments
     * @param array<string, string>|null $env          Environment variables
     * @param int|null                   $timeout      Hard timeout in seconds; null uses attribute/Process default
     * @param bool                       $quiet        When true, suppresses streaming output to $output
     * @param string|null                $outputPrefix Prefix prepended to each streamed output line
     *
     * @throws \InvalidArgumentException When $timeout is negative
     */
    protected function runCommand(string|array $command, OutputInterface $output, ?string $cwd = null, ?array $env = null, ?int $timeout = null, bool $quiet = false, ?string $outputPrefix = null): TaskResult
    {
        $process = \is_string($command)
            ? Process::fromShellCommandline($command, $cwd, $env)
            : new Process($command, $cwd, $env);

        return $this->runProcess($process, $output, $timeout, $quiet, $outputPrefix);
    }

    private function doRunProcess(Process $process, OutputInterface $output, bool $quiet = false, ?string $outputPrefix = null): TaskResult
    {
        $atLineStart = true;

        try {
            $exitCode = $process->run(static function (string $type, string $buffer) use ($output, $quiet, $outputPrefix, &$atLineStart): void {
                if ($quiet) {
                    return;
                }

                $sanitized = ConsoleSanitizer::sanitizeForFormatter($buffer);

                if (null !== $outputPrefix && '' !== $outputPrefix) {
                    $prefixSanitized = ConsoleSanitizer::sanitizeForFormatter($outputPrefix);
                    $lines = \explode("\n", $sanitized);
                    $count = \count($lines);
                    $formattedLines = [];

                    for ($i = 0; $i < $count; ++$i) {
                        if ($i > 0) {
                            $atLineStart = true;
                        }

                        if ($i < $count - 1 || '' !== $lines[$i]) {
                            if ($atLineStart) {
                                $formattedLines[] = $prefixSanitized.$lines[$i];
                                $atLineStart = false;
                            } else {
                                $formattedLines[] = $lines[$i];
                            }
                        } else {
                            $formattedLines[] = '';
                        }
                    }

                    $sanitized = \implode("\n", $formattedLines);
                }

                if (Process::ERR === $type) {
                    $output->write(\sprintf('<error>%s</error>', $sanitized));
                } else {
                    $output->write($sanitized);
                }
            });
        } catch (ProcessTimedOutException $e) {
            if (!$quiet) {
                $output->writeln(\sprintf(
                    '<error>Process timed out after %ss.</error>',
                    $e->getExceededTimeout() ?? '0',
                ));
            }

            return TaskResult::FAILURE;
        } catch (ProcessExceptionInterface $e) {
            if (!$quiet) {
                $output->writeln(\sprintf(
                    '<error>Process error: %s</error>',
                    ConsoleSanitizer::sanitizeForFormatter($e->getMessage()),
                ));
            }

            return TaskResult::FAILURE;
        }

        if (0 !== $exitCode) {
            if (!$quiet) {
                $output->writeln(\sprintf('<error>Process exited with code %d.</error>', $exitCode));
            }

            return TaskResult::FAILURE;
        }

        return TaskResult::SUCCESS;
    }
}
