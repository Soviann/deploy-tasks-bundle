<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\Scenario\Kernel\AbstractLifecycleScenarioKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

abstract class AbstractStorageLifecycleTestCase extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->cleanStorage();
    }

    public function testFullLifecycleAcrossStorageBackend(): void
    {
        $app = new Application(self::kernel());
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $run = new CommandTester($app->find('deploytasks:run'));
        $status = new CommandTester($app->find('deploytasks:status'));
        $reset = new CommandTester($app->find('deploytasks:reset'));
        $rollup = new CommandTester($app->find('deploytasks:rollup'));

        $taskId = AbstractLifecycleScenarioKernel::FIXTURE_TASK_ID;

        // 1. Run → fixture task is Ran. Phase 3 group semantics: the bare run
        //    targets every slot, so the grouped fixtures execute too — the
        //    failing one flips the exit code to FAILURE while the ungrouped
        //    fixture task still lands as Ran.
        self::assertSame(Command::FAILURE, $run->execute([]));
        $exec = $storage->get($taskId);
        self::assertNotNull($exec);
        self::assertSame(TaskStatus::Ran, $exec->status);
        $failing = $storage->get(
            AbstractLifecycleScenarioKernel::FAILING_TASK_ID,
            AbstractLifecycleScenarioKernel::FAILING_TASK_GROUP,
        );
        self::assertNotNull($failing);
        self::assertSame(TaskStatus::Failed, $failing->status);

        // 2. Reset → record gone → run → back to Ran (the failed grouped slot
        //    is retried and fails again, so the exit code stays FAILURE)
        self::assertSame(
            Command::SUCCESS,
            $reset->execute(['id' => $taskId, '--force' => true], ['interactive' => false]),
        );
        self::assertFalse($storage->has($taskId));
        self::assertSame(Command::FAILURE, $run->execute([]));
        $exec = $storage->get($taskId);
        self::assertNotNull($exec);
        self::assertSame(TaskStatus::Ran, $exec->status);

        // 3. Rollup from clean slate → Ran without executing the task body
        $this->cleanStorage();
        self::assertSame(Command::SUCCESS, $rollup->execute(['--force' => true], ['interactive' => false]));
        $exec = $storage->get($taskId);
        self::assertNotNull($exec);
        self::assertSame(TaskStatus::Ran, $exec->status);

        // 4. Status exits 0 after a clean run
        self::assertSame(Command::SUCCESS, $status->execute([]));
    }

    public function testGroupedSlotLifecycle(): void
    {
        $storage = $this->storage();
        $taskId = AbstractLifecycleScenarioKernel::GROUPED_TASK_ID;
        $groupA = AbstractLifecycleScenarioKernel::GROUPED_TASK_GROUP_A;
        $groupB = AbstractLifecycleScenarioKernel::GROUPED_TASK_GROUP_B;

        // 1. Run one group → exactly that slot is recorded
        $run = $this->runConsoleCommand('deploytasks:run', ['--group' => [$groupA]]);
        self::assertSame(Command::SUCCESS, $run->getStatusCode());
        $exec = $storage->get($taskId, $groupA);
        self::assertNotNull($exec);
        self::assertSame(TaskStatus::Ran, $exec->status);
        self::assertSame($groupA, $exec->group);
        self::assertFalse($storage->has($taskId, $groupB));
        self::assertFalse($storage->has($taskId));

        // 2. Run the other group → second slot recorded, first preserved
        $run = $this->runConsoleCommand('deploytasks:run', ['--group' => [$groupB]]);
        self::assertSame(Command::SUCCESS, $run->getStatusCode());
        self::assertTrue($storage->has($taskId, $groupB));
        self::assertTrue($storage->has($taskId, $groupA));

        // 3. Reset one slot only → it is cleared, the other survives
        $reset = $this->runConsoleCommand(
            'deploytasks:reset',
            ['id' => $taskId, '--group' => $groupA, '--force' => true],
            ['interactive' => false],
        );
        self::assertSame(Command::SUCCESS, $reset->getStatusCode());
        self::assertFalse($storage->has($taskId, $groupA));
        self::assertTrue($storage->has($taskId, $groupB));
    }

    public function testFailureIsRecordedAndRetried(): void
    {
        $storage = $this->storage();
        $taskId = AbstractLifecycleScenarioKernel::FAILING_TASK_ID;
        $group = AbstractLifecycleScenarioKernel::FAILING_TASK_GROUP;

        // 1. First run → command fails, slot records Failed with the error text
        $run = $this->runConsoleCommand('deploytasks:run', ['--group' => [$group]]);
        self::assertSame(Command::FAILURE, $run->getStatusCode());
        $exec = $storage->get($taskId, $group);
        self::assertNotNull($exec);
        self::assertSame(TaskStatus::Failed, $exec->status);
        self::assertSame('Task failed!', $exec->error);

        // 2. Failed slot is pending again → second run re-executes and fails again
        $retry = $this->runConsoleCommand('deploytasks:run', ['--group' => [$group]]);
        self::assertSame(Command::FAILURE, $retry->getStatusCode());
        self::assertStringContainsString('1 failed', $retry->getDisplay());
        $exec = $storage->get($taskId, $group);
        self::assertNotNull($exec);
        self::assertSame(TaskStatus::Failed, $exec->status);
    }
}
