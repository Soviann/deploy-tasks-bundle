<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Helper\ProcessRunnerTrait;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[CoversTrait(ProcessRunnerTrait::class)]
final class ProcessRunnerTraitTest extends TestCase
{
    public function testSuccessReturnsSuccessAndStreamsStdout(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            new Process(['php', '-r', 'echo "hello";']),
            $output,
        );

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('hello', $output->fetch());
    }

    public function testNonzeroExitReturnsFailure(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            new Process(['php', '-r', 'exit(3);']),
            $output,
        );

        self::assertSame(TaskResult::FAILURE, $result);
        self::assertStringContainsString('exited with code 3', $output->fetch());
    }

    public function testTimeoutReturnsFailure(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            new Process(['php', '-r', 'sleep(5);'], timeout: 1),
            $output,
        );

        self::assertSame(TaskResult::FAILURE, $result);
        self::assertStringContainsString('timed out', $output->fetch());
    }

    public function testStderrIsWrappedInErrorTags(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            new Process(['php', '-r', 'fwrite(STDERR, "boom");']),
            $output,
        );

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertMatchesRegularExpression('~<error>[^<]*boom[^<]*</error>~', $output->fetch());
    }

    public function testCwdIsRespected(): void
    {
        $output = self::createRawOutput();
        $cwd = \sys_get_temp_dir();

        $result = self::createCaller()->invoke(
            new Process(['php', '-r', 'echo getcwd();'], $cwd),
            $output,
        );

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString($cwd, $output->fetch());
    }

    public function testEnvVarsArePassed(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            new Process(['php', '-r', 'echo getenv("FOO");'], env: ['FOO' => 'bar']),
            $output,
        );

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('bar', $output->fetch());
    }

    public function testProcessExceptionReturnsFailure(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            new Process(['php', '-r', 'echo 1;'], '/nonexistent/dir/xyz'),
            $output,
        );

        self::assertSame(TaskResult::FAILURE, $result);
        self::assertStringContainsString('Process error', $output->fetch());
    }

    private static function createRawOutput(): BufferedOutput
    {
        $output = new BufferedOutput();
        $output->setFormatter(new class extends OutputFormatter {
            public function format(?string $message): ?string
            {
                return $message;
            }
        });

        return $output;
    }

    private static function createCaller(): ProcessRunnerTraitCaller
    {
        return new ProcessRunnerTraitCaller();
    }
}

/**
 * @internal
 */
final class ProcessRunnerTraitCaller
{
    use ProcessRunnerTrait;

    public function invoke(Process $process, OutputInterface $output): TaskResult
    {
        return $this->runProcess($process, $output);
    }
}
