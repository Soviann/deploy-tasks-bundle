<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers the three DEPLOY_TASKS_HOST_* env var overrides that
 * bin/deploy-tasks-host.sh.dist honors but HostRunnerTest does not exercise.
 */
final class HostRunnerEnvOverridesTest extends TestCase
{
    private const RUNNER_SOURCE = __DIR__.'/../../bin/deploy-tasks-host.sh.dist';

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = \sys_get_temp_dir().'/dtb-host-env-'.\uniqid('', true);
        \mkdir($this->workspace.'/bin', 0o755, true);
        \copy(self::RUNNER_SOURCE, $this->workspace.'/bin/deploy-tasks-host.sh');
        \chmod($this->workspace.'/bin/deploy-tasks-host.sh', 0o755);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->workspace)) {
            $this->removeDirectory($this->workspace);
        }
    }

    public function testHostDirOverrideRunsTasksFromAlternateFolder(): void
    {
        \mkdir($this->workspace.'/custom/host-tasks', 0o755, true);
        $marker = $this->workspace.'/ran.txt';
        \file_put_contents(
            $this->workspace.'/custom/host-tasks/20260101_000000_custom.sh',
            "#!/usr/bin/env bash\necho custom >> ".\escapeshellarg($marker)."\n",
        );
        \chmod($this->workspace.'/custom/host-tasks/20260101_000000_custom.sh', 0o755);

        $process = $this->runRunner(['DEPLOY_TASKS_HOST_DIR' => 'custom/host-tasks']);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame("custom\n", \file_get_contents($marker));
    }

    public function testStorageOverrideWritesToAlternatePath(): void
    {
        \mkdir($this->workspace.'/deploy/host-tasks', 0o755, true);
        \file_put_contents(
            $this->workspace.'/deploy/host-tasks/20260101_000000_noop.sh',
            "#!/usr/bin/env bash\nexit 0\n",
        );

        $process = $this->runRunner(['DEPLOY_TASKS_HOST_STORAGE' => '.alt-log']);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertFileExists($this->workspace.'/.alt-log');
        self::assertFileDoesNotExist($this->workspace.'/.deploy-tasks-host.log');
        self::assertSame("20260101_000000_noop\n", \file_get_contents($this->workspace.'/.alt-log'));
    }

    public function testLockOverrideUsesAlternateLockFile(): void
    {
        \mkdir($this->workspace.'/deploy/host-tasks', 0o755, true);
        \file_put_contents(
            $this->workspace.'/deploy/host-tasks/20260101_000000_noop.sh',
            "#!/usr/bin/env bash\nexit 0\n",
        );

        $process = $this->runRunner(['DEPLOY_TASKS_HOST_LOCK' => '.alt-lock']);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertFileExists($this->workspace.'/.alt-lock');
        self::assertFileDoesNotExist($this->workspace.'/.deploy-tasks-host.lock');
    }

    /**
     * @param array<string, string> $env
     */
    private function runRunner(array $env): Process
    {
        $process = new Process(
            ['bash', 'bin/deploy-tasks-host.sh'],
            $this->workspace,
            $env + ['PATH' => \getenv('PATH')],
        );
        $process->run();

        return $process;
    }

    private function removeDirectory(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $path) {
            \assert($path instanceof \SplFileInfo);
            $path->isDir() ? \rmdir($path->getPathname()) : \unlink($path->getPathname());
        }
        \rmdir($dir);
    }
}
