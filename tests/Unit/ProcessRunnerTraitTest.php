<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
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

    public function testTimeoutMessageIncludesActualTimeoutValue(): void
    {
        // Mutant 162 (Coalesce): '$process->getTimeout() ?? "0"' → '"0" ?? $process->getTimeout()'
        // swaps operands so the timeout seconds always reports '0' instead of the real value.
        // This test asserts the real configured timeout appears in the error message.
        $output = self::createRawOutput();

        self::createCaller()->invoke(
            new Process(['php', '-r', 'sleep(5);'], timeout: 2),
            $output,
        );

        self::assertStringContainsString('2s', $output->fetch());
    }

    public function testIdleTimeoutMessageReportsRealExceededTimeoutNotZero(): void
    {
        // With only an idle timeout set, Process::getTimeout() is null, so the old
        // '$process->getTimeout() ?? "0"' fallback misreported the failure as "0s".
        // ProcessTimedOutException::getExceededTimeout() knows which limit fired.
        $output = self::createRawOutput();
        $process = new Process(['php', '-r', 'sleep(5);']);
        $process->setTimeout(null);
        $process->setIdleTimeout(1);

        $result = self::createCaller()->invoke($process, $output);

        self::assertSame(TaskResult::FAILURE, $result);
        $rendered = $output->fetch();
        self::assertStringContainsString('timed out after 1s', $rendered);
        self::assertStringNotContainsString('0s', $rendered);
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

    public function testFailureOutputOmitsCommandLine(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            new Process(['php', '-r', 'exit(3);', '--password=s3cret']),
            $output,
        );

        self::assertSame(TaskResult::FAILURE, $result);
        $rendered = $output->fetch();
        self::assertStringNotContainsString('s3cret', $rendered);
        self::assertStringNotContainsString('--password', $rendered);
    }

    public function testTimeoutOutputOmitsCommandLine(): void
    {
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            new Process(['php', '-r', 'sleep(5);', '--password=s3cret'], timeout: 1),
            $output,
        );

        self::assertSame(TaskResult::FAILURE, $result);
        $rendered = $output->fetch();
        self::assertStringNotContainsString('s3cret', $rendered);
        self::assertStringNotContainsString('--password', $rendered);
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

    public function testProcessExceptionMessageIsSanitizedBeforeOutput(): void
    {
        // Exception messages can carry raw terminal control bytes (e.g. ANSI colour
        // sequences echoed back from a failing command). The catch block must strip
        // them via ConsoleSanitizer before writing to the deployer's terminal.
        // Process embeds the cwd verbatim in its "cwd does not exist" message,
        // giving a deterministic control-byte-bearing exception without mocking.
        $output = self::createRawOutput();

        $result = self::createCaller()->invoke(
            new Process(['php', '-r', 'echo 1;'], "/nonexistent/\e[31mboom\e[0m bell\x07end"),
            $output,
        );

        self::assertSame(TaskResult::FAILURE, $result);
        $rendered = $output->fetch();
        // Control BYTES are removed; the printable "[31m" remainders survive.
        self::assertStringContainsString('[31mboom[0m bellend', $rendered);
        self::assertStringNotContainsString("\e", $rendered);
        self::assertStringNotContainsString("\x07", $rendered);
    }

    public function testAngleBracketStderrDoesNotCorruptFormatter(): void
    {
        // Use a real (decorated=false) BufferedOutput so the formatter actually
        // parses tags. Without OutputFormatter::escape(), the child's literal
        // "<error>boom</error>" would be nested inside the trait's own
        // <error>…</error> wrapper and would confuse the parser (or emit
        // unescaped brackets into the stream).
        $output = new BufferedOutput();

        // Must not throw — that is the primary contract.
        $result = self::createCaller()->invoke(
            Process::fromShellCommandline('>&2 printf "<error>boom</error>"'),
            $output,
        );

        self::assertSame(TaskResult::SUCCESS, $result);

        // After the formatter strips styling tags, the escaped child text
        // (\<error\>boom\</error\>) round-trips back to the literal angle-
        // bracket string.
        $rendered = $output->fetch();
        self::assertStringContainsString('<error>boom</error>', $rendered);
    }

    public function testStreamedStdoutIsStrippedOfControlBytesAndFormatterTags(): void
    {
        $process = Process::fromShellCommandline('printf \'%b\' \'\033]0;pwn\007<error>x</error>\'');

        $output = new BufferedOutput();
        $result = self::createCaller()->invoke($process, $output);

        self::assertSame(TaskResult::SUCCESS, $result);
        $fetched = $output->fetch();
        self::assertStringNotContainsString("\x1b", $fetched, 'ESC bytes must never reach the terminal.');
        self::assertStringContainsString('<error>x</error>', $fetched, 'Formatter tags must render literally, not be interpreted.');
    }

    public function testRunProcessWithTimeoutForwardsSecondsAndDelegates(): void
    {
        $output = self::createRawOutput();

        $process = new class(['php', '-r', 'echo "ok";']) extends Process {
            public ?int $capturedTimeout = null;

            public function setTimeout(?float $timeout): static
            {
                $this->capturedTimeout = (int) $timeout;

                return parent::setTimeout($timeout);
            }
        };

        $caller = new ProcessRunnerTraitWithTimeoutCaller();
        $result = $caller->invokeWithTimeout($process, 42, $output);

        self::assertSame(42, $process->capturedTimeout);
        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('ok', $output->fetch());
    }

    public function testRunProcessWithTimeoutRejectsNegativeSeconds(): void
    {
        $output = self::createRawOutput();
        $caller = new ProcessRunnerTraitWithTimeoutCaller();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timeout -5 in runProcessWithTimeout(): must be >= 0.');

        $caller->invokeWithTimeout(new Process(['php', '-r', 'echo 1;']), -5, $output);
    }

    public function testRunProcessWithTimeoutAcceptsZero(): void
    {
        // 0 stays legal: it disables the hard timeout (Process normalizes 0.0 to
        // null), mirroring #[AsDeployTask(timeout: 0)]'s "no enforcement" meaning.
        $output = self::createRawOutput();
        $caller = new ProcessRunnerTraitWithTimeoutCaller();

        $result = $caller->invokeWithTimeout(new Process(['php', '-r', 'echo "ok";']), 0, $output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('ok', $output->fetch());
    }

    public function testRunProcessAppliesAttributeTimeout(): void
    {
        $output = self::createRawOutput();
        $caller = new ProcessRunnerTraitAttributeTimeoutCaller();

        $result = $caller->run($output);

        self::assertSame(TaskResult::FAILURE, $result);
        self::assertStringContainsString('timed out', $output->fetch());
    }

    public function testRunProcessWithTimeoutOverridesAttributeTimeout(): void
    {
        $output = self::createRawOutput();
        $caller = new ProcessRunnerTraitAttributeTimeoutOverrideCaller();

        $result = $caller->run($output);

        self::assertSame(TaskResult::SUCCESS, $result);
    }

    public function testZeroAttributeTimeoutLeavesProcessOwnTimeoutUntouched(): void
    {
        // #[AsDeployTask(timeout: 0)] means the attribute has no opinion — runProcess()
        // must not call setTimeout(0.0), which Symfony normalizes to NULL (no timeout at
        // all). The Process's own short timeout must still fire.
        $output = self::createRawOutput();
        $caller = new ProcessRunnerTraitZeroAttributeTimeoutCaller();

        $result = $caller->run($output);

        self::assertSame(TaskResult::FAILURE, $result);
        self::assertStringContainsString('timed out', $output->fetch());
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

/**
 * @internal
 */
final class ProcessRunnerTraitWithTimeoutCaller
{
    use ProcessRunnerTrait;

    public function invokeWithTimeout(Process $process, int $seconds, OutputInterface $output): TaskResult
    {
        return $this->runProcessWithTimeout($process, $seconds, $output);
    }
}

/**
 * @internal
 */
#[AsDeployTask(id: 'test.attr_timeout', timeout: 1)]
final class ProcessRunnerTraitAttributeTimeoutCaller implements DeployTaskInterface
{
    use ProcessRunnerTrait;

    public function getDescription(): string
    {
        return '';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return $this->runProcess(new Process(['php', '-r', 'sleep(5);']), $output);
    }
}

/**
 * @internal
 */
#[AsDeployTask(id: 'test.attr_timeout_override', timeout: 1)]
final class ProcessRunnerTraitAttributeTimeoutOverrideCaller implements DeployTaskInterface
{
    use ProcessRunnerTrait;

    public function getDescription(): string
    {
        return '';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return $this->runProcessWithTimeout(new Process(['php', '-r', 'echo 1;']), 10, $output);
    }
}

/**
 * @internal
 */
#[AsDeployTask(id: 'test.attr_timeout_zero', timeout: 0)]
final class ProcessRunnerTraitZeroAttributeTimeoutCaller implements DeployTaskInterface
{
    use ProcessRunnerTrait;

    public function getDescription(): string
    {
        return '';
    }

    public function run(OutputInterface $output): TaskResult
    {
        return $this->runProcess(new Process(['php', '-r', 'sleep(5);'], timeout: 1), $output);
    }
}
