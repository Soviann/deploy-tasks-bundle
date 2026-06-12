<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Fixtures\FailingTask;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
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

    /**
     * @param array{eventsEnabled?: bool, lockEnabled?: bool, extraTasks?: list<class-string<\Soviann\DeployTasksBundle\DeployTaskInterface>>} $kernelOptions
     * @param array<string, mixed>                                                                                                             $bootOptions
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
