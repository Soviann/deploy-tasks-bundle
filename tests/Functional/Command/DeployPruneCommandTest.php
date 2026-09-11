<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksPruneCommand;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksPruneCommand::class)]
final class DeployPruneCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private TaskStorageInterface $storage;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->application = new Application(self::kernel());
        $this->tester = new CommandTester($this->application->find('deploytasks:prune'));

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $this->storage = $storage;

        $this->cleanStorage();
    }

    public function testPruneNoOrphanedRecords(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('No orphaned task execution records found in storage.', $display);
        self::assertStringNotContainsString('Orphaned tasks', $display);
        self::assertStringNotContainsString('Successfully pruned', $display);
        self::assertStringNotContainsString('Prune', $display);
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testPruneSingleOrphanedRecordInteractiveConfirmed(): void
    {
        $this->storage->save(new TaskExecution('orphaned_task_single', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['yes']);
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Orphaned tasks', $display);
        self::assertStringContainsString('orphaned_task_single', $display);
        self::assertStringContainsString('Prune 1 orphaned task execution record from storage?', $display);
        self::assertStringContainsString('Successfully pruned 1 orphaned task execution record.', $display);
        self::assertFalse($this->storage->has('orphaned_task_single'));
    }

    public function testPruneMultipleOrphanedRecordsInteractiveConfirmed(): void
    {
        $this->storage->save(new TaskExecution('orphaned_1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('orphaned_2', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['yes']);
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('orphaned_1', $display);
        self::assertStringContainsString('orphaned_2', $display);
        self::assertStringContainsString('Prune 2 orphaned task execution records from storage?', $display);
        self::assertStringContainsString('Successfully pruned 2 orphaned task execution records.', $display);
        self::assertFalse($this->storage->has('orphaned_1'));
        self::assertFalse($this->storage->has('orphaned_2'));
    }

    public function testPruneInteractiveDeclined(): void
    {
        $this->storage->save(new TaskExecution('orphaned_task', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['no']);
        $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted.', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('orphaned_task'));
    }

    public function testPruneInteractiveEmptyDeclined(): void
    {
        $this->storage->save(new TaskExecution('orphaned_task', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['']);
        $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted.', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('orphaned_task'));
    }

    public function testPruneRefusesNonInteractiveWithoutForce(): void
    {
        $this->storage->save(new TaskExecution('orphaned_task', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute([], ['interactive' => false]);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString('Refusing to run destructive command non-interactively without --force.', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('orphaned_task'));
    }

    public function testPruneNonInteractiveWithForce(): void
    {
        $this->storage->save(new TaskExecution('orphaned_task', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringNotContainsString('Prune 1 orphaned', $display);
        self::assertStringContainsString('Successfully pruned 1 orphaned task execution record.', $display);
        self::assertFalse($this->storage->has('orphaned_task'));
    }

    public function testPruneDryRunSingleOrphanedRecord(): void
    {
        $this->storage->save(new TaskExecution('orphaned_task', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('orphaned_task', $display);
        self::assertStringContainsString('[DRY-RUN] 1 orphaned task execution record would be pruned.', $display);
        self::assertTrue($this->storage->has('orphaned_task'));
    }

    public function testPruneDryRunMultipleOrphanedRecords(): void
    {
        $this->storage->save(new TaskExecution('orphaned_1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('orphaned_2', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('[DRY-RUN] 2 orphaned task execution records would be pruned.', $display);
        self::assertTrue($this->storage->has('orphaned_1'));
        self::assertTrue($this->storage->has('orphaned_2'));
    }

    public function testPruneDryRunAllowedNonInteractiveWithoutForce(): void
    {
        $this->storage->save(new TaskExecution('orphaned_task', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--dry-run' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('[DRY-RUN] 1 orphaned task execution record would be pruned.', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('orphaned_task'));
    }

    public function testPruneGroupFiltering(): void
    {
        $this->storage->save(new TaskExecution('orphaned_default', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('orphaned_pre', TaskStatus::Ran, new \DateTimeImmutable(), group: 'predeploy'));
        $this->storage->save(new TaskExecution('orphaned_post', TaskStatus::Ran, new \DateTimeImmutable(), group: 'postdeploy'));

        $this->tester->execute(['--group' => ['predeploy'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('orphaned_pre', $display);
        self::assertStringContainsString('predeploy', $display);
        self::assertStringNotContainsString('orphaned_default', $display);
        self::assertStringNotContainsString('orphaned_post', $display);
        self::assertStringContainsString('Successfully pruned 1 orphaned task execution record.', $display);

        self::assertFalse($this->storage->has('orphaned_pre', 'predeploy'));
        self::assertTrue($this->storage->has('orphaned_default'));
        self::assertTrue($this->storage->has('orphaned_post', 'postdeploy'));
    }

    public function testPruneGroupFilteringMultipleGroups(): void
    {
        $this->storage->save(new TaskExecution('orphaned_default', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('orphaned_pre', TaskStatus::Ran, new \DateTimeImmutable(), group: 'predeploy'));
        $this->storage->save(new TaskExecution('orphaned_post', TaskStatus::Ran, new \DateTimeImmutable(), group: 'postdeploy'));

        $this->tester->execute(['--group' => ['predeploy', 'postdeploy'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('orphaned_pre', $display);
        self::assertStringContainsString('orphaned_post', $display);
        self::assertStringNotContainsString('orphaned_default', $display);
        self::assertStringContainsString('Successfully pruned 2 orphaned task execution records.', $display);

        self::assertFalse($this->storage->has('orphaned_pre', 'predeploy'));
        self::assertFalse($this->storage->has('orphaned_post', 'postdeploy'));
        self::assertTrue($this->storage->has('orphaned_default'));
    }

    public function testPruneGroupFilteringNoMatches(): void
    {
        $this->storage->save(new TaskExecution('orphaned_pre', TaskStatus::Ran, new \DateTimeImmutable(), group: 'predeploy'));

        $this->tester->execute(['--group' => ['postdeploy'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('No orphaned task execution records found in storage.', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('orphaned_pre', 'predeploy'));
    }

    public function testPruneInvalidGroupName(): void
    {
        $this->tester->execute(['--group' => ['invalid group!'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString('Invalid group name "invalid group!": must match', $this->tester->getDisplay());
    }

    public function testPruneTableFormattingAndTruncation(): void
    {
        $longError = \str_repeat('Error details about failure ', 5); // > 60 chars
        $this->storage->save(new TaskExecution('orphaned_failed', TaskStatus::Failed, new \DateTimeImmutable(), $longError, durationMs: 2500));
        $this->storage->save(new TaskExecution('orphaned_skipped', TaskStatus::Skipped, new \DateTimeImmutable(), durationMs: 120));
        $this->storage->save(new TaskExecution('orphaned_ran_corrupt', TaskStatus::Ran, new \DateTimeImmutable(), error: 'residual_corrupted_error'));
        $this->storage->save(new TaskExecution('orphaned_failed_null_error', TaskStatus::Failed, new \DateTimeImmutable()));

        $this->tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();

        // Verify table headers are rendered
        self::assertStringContainsString('ID', $display);
        self::assertStringContainsString('Group', $display);
        self::assertStringContainsString('Status', $display);
        self::assertStringContainsString('Error', $display);
        self::assertStringContainsString('Executed At', $display);
        self::assertStringContainsString('Duration', $display);

        self::assertStringContainsString('failed', $display);
        self::assertStringContainsString('skipped', $display);
        self::assertStringContainsString('…', $display);
        self::assertStringContainsString('2.5s', $display);
        self::assertStringContainsString('120ms', $display);
        self::assertStringNotContainsString('residual_corrupted_error', $display);
        self::assertStringContainsString('orphaned_failed_null_error', $display);
    }

    public function testPruneTableSortOrder(): void
    {
        $this->storage->save(new TaskExecution('task_z', TaskStatus::Ran, new \DateTimeImmutable('2026-01-01 10:00:00')));
        $this->storage->save(new TaskExecution('task_a', TaskStatus::Ran, new \DateTimeImmutable('2026-01-01 10:00:00'), group: 'group_b'));
        $this->storage->save(new TaskExecution('task_a', TaskStatus::Ran, new \DateTimeImmutable('2026-01-01 10:00:00')));
        $this->storage->save(new TaskExecution('task_a', TaskStatus::Ran, new \DateTimeImmutable('2026-01-01 10:00:00'), group: 'group_a'));

        $this->tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertMatchesRegularExpression('/task_a\s+—.*?task_a\s+group_a.*?task_a\s+group_b.*?task_z\s+—/s', $display);
    }

    public function testPruneOrphanedGroupSlotOnActiveTask(): void
    {
        // test.simple only has default slot; a record with group 'old_slot' is orphaned
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable(), group: 'old_slot'));
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Successfully pruned 1 orphaned task execution record.', $this->tester->getDisplay());

        // Stale slot pruned, active default slot preserved
        self::assertFalse($this->storage->has('test.simple', 'old_slot'));
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testPruneWithTransactionalStorage(): void
    {
        $registry = self::getContainer()->get(TaskRegistry::class);
        \assert($registry instanceof TaskRegistry);

        $transactionalStorage = new class($this->storage) implements TaskStorageInterface, TransactionalStorageInterface {
            public bool $transactionalCalled = false;

            public function __construct(private readonly TaskStorageInterface $inner)
            {
            }

            public function has(string $taskId, ?string $group = null): bool
            {
                return $this->inner->has($taskId, $group);
            }

            public function get(string $taskId, ?string $group = null): ?TaskExecution
            {
                return $this->inner->get($taskId, $group);
            }

            public function save(TaskExecution $execution): void
            {
                $this->inner->save($execution);
            }

            public function remove(string $taskId, ?string $group = null): void
            {
                $this->inner->remove($taskId, $group);
            }

            public function removeAll(string $taskId): void
            {
                $this->inner->removeAll($taskId);
            }

            public function findByTaskId(string $taskId): array
            {
                return $this->inner->findByTaskId($taskId);
            }

            public function all(): array
            {
                return $this->inner->all();
            }

            public function reset(): void
            {
                $this->inner->reset();
            }

            public function transactional(\Closure $callback): mixed
            {
                $this->transactionalCalled = true;

                return $callback();
            }
        };

        $transactionalStorage->save(new TaskExecution('orphaned_tx', TaskStatus::Ran, new \DateTimeImmutable()));

        $command = new DeployTasksPruneCommand($registry, $transactionalStorage);
        $tester = new CommandTester($command);
        $tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($transactionalStorage->transactionalCalled);
        self::assertFalse($transactionalStorage->has('orphaned_tx'));
    }
}
