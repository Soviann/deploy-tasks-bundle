<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\RunsProcesses;
use Soviann\DeployTasksBundle\TaskResult;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversTrait(RunsProcesses::class)]
final class RunsProcessesTest extends TestCase
{
    public function testSuccessReturnsSuccessAndStreamsStdout(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            ['php', '-r', 'echo "hello";'],
            $output,
        );

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('hello', $output->fetch());
    }

    public function testNonzeroExitReturnsFailure(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            ['php', '-r', 'exit(3);'],
            $output,
        );

        self::assertSame(TaskResult::FAILURE, $result);
        self::assertStringContainsString('exited with code 3', $output->fetch());
    }

    public function testTimeoutReturnsFailure(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            ['php', '-r', 'sleep(5);'],
            $output,
            timeout: 1,
        );

        self::assertSame(TaskResult::FAILURE, $result);
        self::assertStringContainsString('timed out', $output->fetch());
    }

    public function testStderrIsWrappedInErrorTags(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            ['php', '-r', 'fwrite(STDERR, "boom");'],
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
            ['php', '-r', 'echo getcwd();'],
            $output,
            cwd: $cwd,
        );

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString($cwd, $output->fetch());
    }

    public function testEnvVarsArePassed(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            ['php', '-r', 'echo getenv("FOO");'],
            $output,
            env: ['FOO' => 'bar'],
        );

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('bar', $output->fetch());
    }

    public function testProcessExceptionReturnsFailure(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            ['php', '-r', 'echo 1;'],
            $output,
            cwd: '/nonexistent/dir/xyz',
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

    private static function createCaller(): RunsProcessesCaller
    {
        return new RunsProcessesCaller();
    }
}

/**
 * @internal
 */
final class RunsProcessesCaller
{
    use RunsProcesses;

    /**
     * @param list<string>               $command
     * @param array<string, string>|null $env
     */
    public function invoke(
        array $command,
        OutputInterface $output,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?int $timeout = null,
    ): TaskResult {
        return $this->runProcess($command, $output, $cwd, $env, $input, $timeout);
    }
}
