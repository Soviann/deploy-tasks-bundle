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

        // 1. Run → fixture task is Ran
        self::assertSame(Command::SUCCESS, $run->execute([]));
        $exec = $storage->get($taskId);
        self::assertNotNull($exec);
        self::assertSame(TaskStatus::Ran, $exec->status);

        // 2. Reset → record gone → run → back to Ran
        self::assertSame(Command::SUCCESS, $reset->execute(['id' => $taskId, '--force' => true], ['interactive' => false]));
        self::assertFalse($storage->has($taskId));
        self::assertSame(Command::SUCCESS, $run->execute([]));
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
}
