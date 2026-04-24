<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksResetCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipCommand;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksSkipCommand::class)]
#[CoversClass(DeployTasksResetCommand::class)]
final class DeploySkipResetEdgeCasesTest extends FunctionalTestCase
{
    private CommandTester $skipTester;
    private CommandTester $resetTester;
    private TaskStorageInterface $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->skipTester = new CommandTester($application->find('deploytasks:skip'));
        $this->resetTester = new CommandTester($application->find('deploytasks:reset'));

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $this->storage = $storage;

        $this->cleanStorage();
    }

    public function testSkipAlreadySkippedTaskIsIdempotent(): void
    {
        $this->skipTester->execute(['id' => 'test.simple', '--no-interaction' => true]);
        self::assertSame(Command::SUCCESS, $this->skipTester->getStatusCode());

        // Skip the same task a second time — should still succeed
        $this->skipTester->execute(['id' => 'test.simple', '--no-interaction' => true]);
        self::assertSame(Command::SUCCESS, $this->skipTester->getStatusCode());

        // Status must remain Skipped
        $execution = $this->storage->get('test.simple');
        \assert(null !== $execution);
        self::assertSame(TaskStatus::Skipped, $execution->status);
    }

    public function testResetFailedTaskMakesItPending(): void
    {
        // Manually store a Failed execution
        $this->storage->save(new TaskExecution(
            id: 'test.simple',
            status: TaskStatus::Failed,
            executedAt: new \DateTimeImmutable(),
            error: 'Simulated failure',
        ));

        self::assertTrue($this->storage->has('test.simple'));
        self::assertSame(TaskStatus::Failed, $this->storage->get('test.simple')?->status);

        // Reset it
        $this->resetTester->execute(['id' => 'test.simple', '--no-interaction' => true]);

        self::assertSame(Command::SUCCESS, $this->resetTester->getStatusCode());
        self::assertFalse($this->storage->has('test.simple'), 'After reset, task must be back to pending (no record)');
    }

    public function testResetRanTaskMakesItPending(): void
    {
        $this->storage->save(new TaskExecution(
            id: 'test.simple',
            status: TaskStatus::Ran,
            executedAt: new \DateTimeImmutable(),
        ));

        $this->resetTester->execute(['id' => 'test.simple', '--no-interaction' => true]);

        self::assertSame(Command::SUCCESS, $this->resetTester->getStatusCode());
        self::assertFalse($this->storage->has('test.simple'));
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
