<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Component\Process\Process;

/**
 * Covers the environment-variable surface of bin/deploy-tasks-host.sh.dist
 * that HostRunnerTest does not exercise: the three DEPLOY_TASKS_HOST_* path
 * overrides, APP_ENV validation, and real-env-wins dotenv precedence.
 */
final class HostRunnerEnvOverridesTest extends FunctionalTestCase
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
        parent::tearDown();

        FilesystemTestHelper::cleanup($this->workspace);
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

    public function testRealEnvironmentVariableWinsOverDotenvFiles(): void
    {
        // Symfony Dotenv semantics: a variable already present in the process
        // environment (e.g. CI-injected DATABASE_URL) must never be
        // overwritten by any .env file in the cascade.
        \file_put_contents($this->workspace.'/.env', "DATABASE_URL=from-dotenv\n");
        \file_put_contents($this->workspace.'/.env.dev.local', "DATABASE_URL=from-dotenv-local\n");

        \mkdir($this->workspace.'/deploy/host-tasks', 0o755, true);
        $output = $this->workspace.'/db.txt';
        \file_put_contents(
            $this->workspace.'/deploy/host-tasks/20260101_000000_echo_db.sh',
            "#!/usr/bin/env bash\necho \"\$DATABASE_URL\" > ".\escapeshellarg($output)."\n",
        );
        \chmod($this->workspace.'/deploy/host-tasks/20260101_000000_echo_db.sh', 0o755);

        $process = $this->runRunner(['DATABASE_URL' => 'from-real-env']);

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame("from-real-env\n", \file_get_contents($output));
    }

    #[DataProvider('invalidAppEnvProvider')]
    public function testInvalidAppEnvIsRejectedBeforeEnvLoading(string $appEnv): void
    {
        // No host-tasks directory needed — the runner must bail before env loading.
        $process = $this->runRunner(['APP_ENV' => $appEnv]);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('Invalid APP_ENV', $process->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAppEnvProvider(): iterable
    {
        yield 'path traversal' => ['../../tmp/foo'];
        yield 'slash-only traversal' => ['../evil'];
        yield 'shell metacharacter' => ['prod;rm'];
        yield 'whitespace' => ['prod env'];
        yield 'leading dot' => ['.prod'];
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
}
