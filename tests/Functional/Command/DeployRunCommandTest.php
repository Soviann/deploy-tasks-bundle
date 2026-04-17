<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksRunCommand::class)]
final class DeployRunCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:run'));
        $this->cleanStorage();
    }

    public function testRunAllTasks(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('ran', $this->tester->getDisplay());
    }

    public function testDryRun(): void
    {
        $this->tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('pending', $this->tester->getDisplay());

        // Verify no tasks were actually executed
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertEmpty($storage->all());
    }

    public function testForceRerunsAllTasks(): void
    {
        // First run
        $this->tester->execute([]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Force re-run all
        $this->tester->execute(['--force' => true]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('ran', $this->tester->getDisplay());
    }

    public function testIdRunsSingleTask(): void
    {
        $this->tester->execute(['--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertTrue($storage->has('test.simple'));
    }

    public function testIdSkipsAlreadyExecutedTask(): void
    {
        // First run
        $this->tester->execute(['--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Second run — already executed, skipped
        $this->tester->execute(['--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('already been executed', $this->tester->getDisplay());
    }

    public function testIdWithUnregisteredTaskFails(): void
    {
        $this->tester->execute(['--id' => 'nonexistent.task']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('not registered', $this->tester->getDisplay());
    }

    public function testForceWithIdRerunsSingleTask(): void
    {
        // First run
        $this->tester->execute(['--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Force re-run single task
        $this->tester->execute(['--force' => true, '--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
    }

    public function testRunAllAlreadyExecuted(): void
    {
        // First run
        $this->tester->execute([]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Second run — all already executed
        $this->tester->execute([]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('nothing to run', $this->tester->getDisplay());
    }

    public function testPrioritizedTaskRunsBeforeSimpleTask(): void
    {
        $this->tester->execute(['--dry-run' => true]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('test.prioritized', $display);
        self::assertStringContainsString('test.simple', $display);

        $prioritizedPos = \strpos($display, 'test.prioritized');
        $simplePos = \strpos($display, 'test.simple');

        self::assertNotFalse($prioritizedPos);
        self::assertNotFalse($simplePos);
        self::assertLessThan($simplePos, $prioritizedPos, 'test.prioritized (priority=10) must appear before test.simple (priority=0)');
    }

    public function testSkippingTaskIsStoredAsSkipped(): void
    {
        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $execution = $storage->get('test.skipping');
        self::assertNotNull($execution, 'SkippingTask should be stored after run');
        self::assertSame(TaskStatus::Skipped, $execution->status);
    }

    public function testSkippingTaskIsNotRerunWithoutForce(): void
    {
        $this->tester->execute([]); // first run — SkippingTask stored as Skipped
        $this->tester->execute([]); // second run — should skip it (already executed)

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        // Skipped task is not retried on a normal run
        self::assertStringNotContainsString('test.skipping ran', $this->tester->getDisplay());
    }

    public function testNoFlagRunsOnlyDefaultTasks(): void
    {
        $this->tester->execute([]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.simple'));
        self::assertFalse($storage->has('test.predeploy', 'predeploy'));
        self::assertFalse($storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($storage->has('test.multi_group', 'postdeploy'));
    }

    public function testGroupFlagRunsOnlyMatchingTasks(): void
    {
        $this->tester->execute(['--group' => ['predeploy']]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.predeploy', 'predeploy'));
        self::assertTrue($storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($storage->has('test.simple'));
        self::assertFalse($storage->has('test.multi_group', 'postdeploy'));
    }

    public function testMultipleGroupFlagsUnion(): void
    {
        $this->tester->execute(['--group' => ['predeploy', 'postdeploy']]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.predeploy', 'predeploy'));
        self::assertTrue($storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($storage->has('test.multi_group', 'postdeploy'));
    }

    public function testMultiGroupTaskTwoSeparateCalls(): void
    {
        $this->tester->execute(['--group' => ['predeploy']]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $this->tester->execute(['--group' => ['postdeploy']]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($storage->has('test.multi_group', 'postdeploy'));
    }

    public function testMultiGroupTaskOneCombinedCall(): void
    {
        $this->tester->execute(['--group' => ['predeploy', 'postdeploy']]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($storage->has('test.multi_group', 'postdeploy'));
    }

    public function testGroupNoMatchStillSuccess(): void
    {
        $this->tester->execute(['--group' => ['nonexistent']]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
    }

    public function testIdOnlyOnGroupedTaskFailsInvalid(): void
    {
        $this->tester->execute(['--id' => 'test.predeploy']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
    }

    public function testIdWithGroupRunsSingleSlot(): void
    {
        $this->tester->execute(['--id' => 'test.multi_group', '--group' => ['predeploy']]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($storage->has('test.multi_group', 'postdeploy'));
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
