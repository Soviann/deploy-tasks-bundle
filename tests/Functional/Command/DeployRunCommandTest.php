<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasks\Bundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasks\Tests\Functional\TestKernel;
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
        $application = new Application(self::$kernel);
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

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
