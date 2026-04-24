<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Component\Process\Process;

/**
 * End-to-end coverage for the host-scope bash runner (bin/deploy-tasks-host.sh.dist).
 *
 * Each test lays out a disposable workspace that mirrors the Flex-installed
 * project layout (project root with a bin/ copy of the runner) and invokes
 * it via Symfony Process.
 */
final class HostRunnerTest extends FunctionalTestCase
{
    private const RUNNER_SOURCE = __DIR__.'/../../bin/deploy-tasks-host.sh.dist';

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = \sys_get_temp_dir().'/deploy-tasks-host-'.\uniqid('', true);
        \mkdir($this->workspace.'/bin', 0o755, true);
        \copy(self::RUNNER_SOURCE, $this->workspace.'/bin/deploy-tasks-host.sh');
        \chmod($this->workspace.'/bin/deploy-tasks-host.sh', 0o755);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        FilesystemTestHelper::cleanup($this->workspace);
    }

    public function testExecutesPendingTasksInFilenameOrder(): void
    {
        $output = $this->workspace.'/order.txt';
        $this->writeTask('20260101_000000_first', \sprintf('echo first >> %s', \escapeshellarg($output)));
        $this->writeTask('20260102_000000_second', \sprintf('echo second >> %s', \escapeshellarg($output)));

        $process = $this->runRunner();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame("first\nsecond\n", \file_get_contents($output));

        $log = \file_get_contents($this->workspace.'/.deploy-tasks-host.log');
        self::assertSame("20260101_000000_first\n20260102_000000_second\n", $log);
    }

    public function testSkipsTasksAlreadyInStorage(): void
    {
        $output = $this->workspace.'/ran.txt';
        $this->writeTask('20260101_000000_alpha', \sprintf('echo alpha >> %s', \escapeshellarg($output)));
        $this->writeTask('20260102_000000_beta', \sprintf('echo beta >> %s', \escapeshellarg($output)));
        \file_put_contents($this->workspace.'/.deploy-tasks-host.log', "20260101_000000_alpha\n");

        $process = $this->runRunner();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame("beta\n", \file_get_contents($output));
        self::assertStringContainsString('✓ 20260101_000000_alpha (skipped)', $process->getOutput());
    }

    public function testFailsFastAndDoesNotMarkFailedTask(): void
    {
        $output = $this->workspace.'/ran.txt';
        $this->writeTask('20260101_000000_ok', \sprintf('echo ok >> %s', \escapeshellarg($output)));
        $this->writeTask('20260102_000000_boom', 'exit 1');
        $this->writeTask('20260103_000000_never', \sprintf('echo never >> %s', \escapeshellarg($output)));

        $process = $this->runRunner();

        self::assertSame(1, $process->getExitCode());
        self::assertSame("ok\n", \file_get_contents($output));

        $log = \file_get_contents($this->workspace.'/.deploy-tasks-host.log');
        self::assertSame("20260101_000000_ok\n", $log);
        self::assertStringContainsString('✗ 20260102_000000_boom failed', $process->getOutput());
    }

    public function testLoadsEnvCascadeInSymfonyOrder(): void
    {
        \file_put_contents($this->workspace.'/.env', "FOO=a\n");
        \file_put_contents($this->workspace.'/.env.prod', "FOO=b\n");
        \file_put_contents($this->workspace.'/.env.local', "FOO=c\n");
        \file_put_contents($this->workspace.'/.env.prod.local', "FOO=d\n");

        $output = $this->workspace.'/foo.txt';
        $this->writeTask('20260101_000000_echo_foo', \sprintf('echo "$FOO" > %s', \escapeshellarg($output)));

        $process = $this->runRunner(['prod']);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame("d\n", \file_get_contents($output));
    }

    public function testLoadsLocalOverrideScriptAfterEnvCascade(): void
    {
        \file_put_contents($this->workspace.'/.env.prod.local', "BAR=x\n");
        \file_put_contents($this->workspace.'/deploy-tasks-host.local.sh', "export BAR=y\n");

        $output = $this->workspace.'/bar.txt';
        $this->writeTask('20260101_000000_echo_bar', \sprintf('echo "$BAR" > %s', \escapeshellarg($output)));

        $process = $this->runRunner(['prod']);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame("y\n", \file_get_contents($output));
    }

    public function testDryRunDoesNotExecuteOrMarkTasks(): void
    {
        $output = $this->workspace.'/ran.txt';
        $this->writeTask('20260101_000000_alpha', \sprintf('echo alpha >> %s', \escapeshellarg($output)));
        $this->writeTask('20260102_000000_beta', \sprintf('echo beta >> %s', \escapeshellarg($output)));

        $process = $this->runRunner(['--dry-run']);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertFalse(\file_exists($output));
        self::assertSame('', \file_get_contents($this->workspace.'/.deploy-tasks-host.log'));
        self::assertStringContainsString('→ 20260101_000000_alpha (dry-run)', $process->getOutput());
        self::assertStringContainsString('→ 20260102_000000_beta (dry-run)', $process->getOutput());
    }

    public function testConcurrentInvocationIsRejected(): void
    {
        $barrier = $this->workspace.'/barrier';
        $this->writeTask('20260101_000000_slow', \sprintf(
            'while [ ! -f %s ]; do sleep 0.05; done',
            \escapeshellarg($barrier),
        ));

        $first = $this->startRunner();
        // Busy-wait until the first invocation has actually grabbed the lock.
        $deadline = \microtime(true) + 5.0;
        while (!\file_exists($this->workspace.'/.deploy-tasks-host.lock') && \microtime(true) < $deadline) {
            \usleep(20_000);
        }

        $second = $this->runRunner();

        // Release the first invocation.
        \file_put_contents($barrier, '');
        $first->wait();

        self::assertSame(0, $first->getExitCode(), $first->getOutput().$first->getErrorOutput());
        self::assertNotSame(0, $second->getExitCode());
        self::assertStringContainsString('Another deploy-tasks-host run is in progress', $second->getOutput());
    }

    public function testMissingTasksDirIsNonFatal(): void
    {
        // No deploy/host-tasks/ directory created.
        $process = $this->runRunner();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame('', \trim($process->getOutput()));
    }

    private function writeTask(string $id, string $body): void
    {
        $dir = $this->workspace.'/deploy/host-tasks';
        if (!\is_dir($dir)) {
            \mkdir($dir, 0o755, true);
        }
        $path = $dir.'/'.$id.'.sh';
        \file_put_contents($path, "#!/usr/bin/env bash\nset -euo pipefail\n".$body."\n");
        \chmod($path, 0o755);
    }

    /**
     * @param list<string> $args
     */
    private function runRunner(array $args = []): Process
    {
        $process = $this->startRunner($args);
        $process->wait();

        return $process;
    }

    /**
     * @param list<string> $args
     */
    private function startRunner(array $args = []): Process
    {
        $process = new Process(
            \array_merge(['bash', 'bin/deploy-tasks-host.sh'], $args),
            $this->workspace,
        );
        $process->start();

        return $process;
    }
}
