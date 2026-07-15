<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Fixtures\FailingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProdOnlyGroupedTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProdOnlyTask;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\KernelConfig;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksRunCommand::class)]
final class DeployRunEnvTest extends FunctionalTestCase
{
    private CommandTester $tester;

    // --- Failing task tests ---

    public function testRunWithFailingTaskReturnsFailure(): void
    {
        $this->bootAndBuildTester(['extraTasks' => [FailingTask::class]]);

        $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('test.failing', $this->tester->getDisplay());
        self::assertStringContainsString('failed', $this->tester->getDisplay());
    }

    public function testFailedTaskIsRecordedInStorage(): void
    {
        $this->bootAndBuildTester(['extraTasks' => [FailingTask::class]]);

        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $execution = $storage->get('test.failing');
        self::assertNotNull($execution, 'Failed task should be stored');
        self::assertSame(TaskStatus::Failed, $execution->status);
        self::assertNotNull($execution->error, 'Error message should be stored');
        self::assertStringContainsString('Task failed!', $execution->error);
    }

    public function testFailedTaskIsRetriedOnNextRun(): void
    {
        $this->bootAndBuildTester(['extraTasks' => [FailingTask::class]]);

        $this->tester->execute([]); // first run — test.failing stored as Failed
        $this->tester->execute([]); // second run — Failed tasks are retried

        // test.simple ran on first run; on second run it's skipped (already Ran).
        // test.failing is retried because its status is Failed.
        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('test.failing', $this->tester->getDisplay());
    }

    // --- Environment filtering tests ---

    public function testProdOnlyTaskIsExcludedInTestEnv(): void
    {
        $this->bootAndBuildTester();

        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertFalse($storage->has('test.prod_only'), 'Prod-only task must not run in test env');
    }

    public function testProdOnlyTaskRunsInProdEnv(): void
    {
        $this->bootAndBuildTester([], ['environment' => 'prod']);

        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.prod_only'), 'Prod-only task must run in prod env');
    }

    public function testMultiEnvTaskRunsInTestEnv(): void
    {
        $this->bootAndBuildTester();

        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.multi_env'), 'Multi-env task (dev+test) must run in test env');
    }

    public function testRunByIdRefusesEnvMismatchWithCleanError(): void
    {
        $this->bootAndBuildTester();

        $this->tester->execute(['--id' => 'test.prod_only']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString('restricted to env', $this->tester->getDisplay());
    }

    public function testMultiEnvTaskIsExcludedInProdEnv(): void
    {
        $this->bootAndBuildTester([], ['environment' => 'prod']);

        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertFalse($storage->has('test.multi_env'), 'Multi-env task (dev+test) must not run in prod env');
    }

    // --- --require-some × environment filter ---

    public function testRequireSomeExitsUsageWhenOnlyEnvMismatchedTasksMatchGroup(): void
    {
        // The only task declaring group "prodonly" is env-restricted to prod; in the
        // test env the runner filters it out. The gate must derive "nothing matched"
        // from the run result — a registry group match alone must not satisfy it.
        $this->bootAndBuildTester(['extraTasks' => [ProdOnlyGroupedTask::class]]);

        $this->tester->execute(['--require-some' => true, '--group' => ['prodonly']]);

        self::assertSame(DeployTasksRunCommand::EX_USAGE, $this->tester->getStatusCode());
        self::assertStringContainsString('No task matched', $this->tester->getDisplay());
    }

    public function testRequireSomeDryRunExitsUsageWhenOnlyEnvMismatchedTasksMatchGroup(): void
    {
        $this->bootAndBuildTester(['extraTasks' => [ProdOnlyGroupedTask::class]]);

        $this->tester->execute(['--require-some' => true, '--dry-run' => true, '--group' => ['prodonly']]);

        self::assertSame(DeployTasksRunCommand::EX_USAGE, $this->tester->getStatusCode());
    }

    public function testRequireSomeSucceedsWhenAllMatchedTasksAlreadyExecuted(): void
    {
        // Already-executed slots count as "matched" (they are reported as skipped):
        // --require-some guards against a filter matching nothing, not against no-ops.
        $this->bootAndBuildTester();

        $this->tester->execute([]);
        $this->tester->execute(['--require-some' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
    }

    public function testRequireSomeWithIdEnvMismatchExitsUsage(): void
    {
        // --id targeting an env-mismatched task under --require-some is "no task
        // matched the filters" — the documented usage exit code, not INVALID.
        $this->bootAndBuildTester();

        $this->tester->execute(['--require-some' => true, '--id' => 'test.prod_only']);

        self::assertSame(DeployTasksRunCommand::EX_USAGE, $this->tester->getStatusCode());
    }

    // --- "No tasks" vs "all filtered out" (Task 1.17) ---

    public function testAllTasksExcludedByEnvironmentShowsDistinctMessage(): void
    {
        // Only a prod-restricted task is registered — no ungrouped/env-agnostic
        // task like the shared TestKernel's SimpleTask — so under APP_ENV=dev the
        // environment filter excludes the entire registry.
        self::useConfigurableKernel(
            KernelConfig::customStorageExtension(),
            KernelConfig::customStorageServices() + [
                'test.task.prod_only' => [
                    'class' => ProdOnlyTask::class,
                    'tags' => ['soviann_deploy_tasks.task'],
                ],
            ],
        );
        self::bootKernel(['environment' => 'dev']);
        $this->cleanStorage();

        $tester = new CommandTester((new Application(self::kernel()))->find('deploytasks:run'));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('1 task(s) registered, none match environment "dev".', $display);
        self::assertStringNotContainsString('No deploy tasks registered.', $display);
    }

    public function testTrulyEmptyRegistryStillShowsNoTasksRegisteredMessage(): void
    {
        // No task service at all — as opposed to the previous test, where a task
        // is registered but excluded by the environment filter. The generic
        // message must still apply here, unqualified by any environment.
        self::useConfigurableKernel(KernelConfig::customStorageExtension(), KernelConfig::customStorageServices());
        self::bootKernel(['environment' => 'dev']);
        $this->cleanStorage();

        $tester = new CommandTester((new Application(self::kernel()))->find('deploytasks:run'));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('No deploy tasks registered.', $display);
        self::assertStringNotContainsString('none match environment', $display);
    }

    public function testGroupedOnlyRegistryWithoutGroupFlagDoesNotBlameEnvironment(): void
    {
        // The only registered task is BOTH grouped and env-restricted. Without
        // --group it would not run even in a matching environment, so the empty
        // run must not blame the environment — that would point the operator at
        // the wrong cause. The env check is scoped to default-slot candidates,
        // of which there are none here, so the generic message applies.
        self::useConfigurableKernel(
            KernelConfig::customStorageExtension(),
            KernelConfig::customStorageServices() + [
                'test.task.prod_only_grouped' => [
                    'class' => ProdOnlyGroupedTask::class,
                    'tags' => ['soviann_deploy_tasks.task'],
                ],
            ],
        );
        self::bootKernel(['environment' => 'dev']);
        $this->cleanStorage();

        $tester = new CommandTester((new Application(self::kernel()))->find('deploytasks:run'));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringNotContainsString('none match environment', $display);
        self::assertStringContainsString('No deploy tasks registered.', $display);
    }

    /**
     * @param array{
     *     eventsEnabled?: bool,
     *     lockEnabled?: bool,
     *     extraTasks?: list<class-string<\Soviann\DeployTasksBundle\DeployTaskInterface>>,
     * } $kernelOptions
     * @param array<string, mixed> $bootOptions
     */
    private function bootAndBuildTester(array $kernelOptions = [], array $bootOptions = []): void
    {
        static::$class = TestKernel::class;
        self::$testKernelOptions = $kernelOptions;
        self::bootKernel($bootOptions);
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:run'));
        $this->cleanStorage();
    }
}
