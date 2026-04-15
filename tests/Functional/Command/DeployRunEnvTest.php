<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasks\Bundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Tests\Functional\FailingTaskKernel;
use Soviann\DeployTasks\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasks\Tests\Functional\TestKernel;
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
        $this->bootAndBuildTester(FailingTaskKernel::class);

        $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('test.failing', $this->tester->getDisplay());
        self::assertStringContainsString('failed', $this->tester->getDisplay());
    }

    public function testFailedTaskIsRecordedInStorage(): void
    {
        $this->bootAndBuildTester(FailingTaskKernel::class);

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
        $this->bootAndBuildTester(FailingTaskKernel::class);

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
        $this->bootAndBuildTester(TestKernel::class);

        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertFalse($storage->has('test.prod_only'), 'Prod-only task must not run in test env');
    }

    public function testProdOnlyTaskRunsInProdEnv(): void
    {
        $this->bootAndBuildTester(TestKernel::class, ['environment' => 'prod']);

        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.prod_only'), 'Prod-only task must run in prod env');
    }

    public function testMultiEnvTaskRunsInTestEnv(): void
    {
        $this->bootAndBuildTester(TestKernel::class);

        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.multi_env'), 'Multi-env task (dev+test) must run in test env');
    }

    public function testMultiEnvTaskIsExcludedInProdEnv(): void
    {
        $this->bootAndBuildTester(TestKernel::class, ['environment' => 'prod']);

        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertFalse($storage->has('test.multi_env'), 'Multi-env task (dev+test) must not run in prod env');
    }

    private function bootAndBuildTester(string $kernelClass, array $options = []): void
    {
        static::$class = $kernelClass;
        self::bootKernel($options);
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('deploytasks:run'));
        $this->cleanStorage();
    }
}
