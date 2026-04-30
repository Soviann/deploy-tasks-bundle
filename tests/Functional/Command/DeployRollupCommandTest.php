<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupCommand;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksRollupCommand::class)]
final class DeployRollupCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private TaskStorageInterface $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:rollup'));

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $this->storage = $storage;

        $this->cleanStorage();
    }

    public function testRollup(): void
    {
        // Save a record for a registered task and a stale one
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('stale.nonexistent', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['yes']);
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());

        // All registered tasks should be marked as run
        self::assertTrue($this->storage->has('test.simple'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('test.simple')?->status);

        // Stale record should be gone
        self::assertFalse($this->storage->has('stale.nonexistent'));
    }

    public function testRollupAborted(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['no']);
        $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted', $this->tester->getDisplay());

        // Storage should be unchanged
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testRollupNoInteractionWithoutForceRefuses(): void
    {
        $this->tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString('Refusing to run destructive command', $this->tester->getDisplay());
    }

    public function testRollupNoInteractionWithForce(): void
    {
        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());

        // All registered tasks should be marked as run
        self::assertTrue($this->storage->has('test.simple'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('test.simple')?->status);
    }

    public function testRollupNoInteractionWithYesAlias(): void
    {
        $this->tester->execute(['--yes' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());

        self::assertTrue($this->storage->has('test.simple'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('test.simple')?->status);
    }

    public function testRollupInteractiveYes(): void
    {
        $this->tester->setInputs(['yes']);
        $this->tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testRollupInteractiveNo(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['no']);
        $this->tester->execute([], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testRollupIdempotent(): void
    {
        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Run again
        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testRollupMarksEverySlotAcrossAllTasks(): void
    {
        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        self::assertTrue($this->storage->has('test.simple'));
        self::assertTrue($this->storage->has('test.predeploy', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testRollupWithGroupMarksOnlyMatchingSlots(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--group' => ['predeploy'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        self::assertTrue($this->storage->has('test.predeploy', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($this->storage->has('test.multi_group', 'postdeploy'));
        // default-slot record must be preserved when filtering by group
        self::assertTrue($this->storage->has('test.simple'));
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
