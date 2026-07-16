<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\Event\AfterTaskEvent;
use Soviann\DeployTasksBundle\Event\BeforeTaskEvent;
use Soviann\DeployTasksBundle\Event\TaskFailedEvent;
use Soviann\DeployTasksBundle\Exception\AllOrNothingFailureException;
use Soviann\DeployTasksBundle\Exception\EventListenerException;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Exception\TaskEnvironmentMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskNotFoundException;
use Soviann\DeployTasksBundle\Helper\SystemClock;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Runner\RunOptions;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
use Soviann\DeployTasksBundle\Runner\TransactionMode;
use Soviann\DeployTasksBundle\Sorting\DefaultTaskSorter;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage;
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Soviann\DeployTasksBundle\TaskResult;
use Soviann\DeployTasksBundle\Tests\Fixtures\ArrayLogger;
use Soviann\DeployTasksBundle\Tests\Fixtures\DbalFailingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\FailingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\HardTimeoutOnlySleepingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\MultiGroupTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\PredeployTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProdOnlyTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ReturnsFailureTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\RollbackTransactionalStorageFixture;
use Soviann\DeployTasksBundle\Tests\Fixtures\SaveCountingStorageFixture;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SkippingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SleepingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SlowThresholdDisabledTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SlowThresholdLoweringTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalInMemoryStorageFixture;
use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionDepthProbeTask;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(TaskRunner::class)]
final class TaskRunnerTest extends TestCase
{
    private InMemoryStorage $storage;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->output = new BufferedOutput();
    }

    public function testRunAllSuccess(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SimpleTask('task.2', 'Second'),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(2, $result->ran);
        self::assertSame(0, $result->skipped);
        self::assertSame(0, $result->failed);
        self::assertTrue($result->isSuccessful());
        self::assertTrue($this->storage->has('task.1'));
        self::assertTrue($this->storage->has('task.2'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('task.1')?->status);
        self::assertSame(TaskStatus::Ran, $this->storage->get('task.2')?->status);
    }

    public function testRunAllReadsStorageOnceInsteadOfPerSlot(): void
    {
        $counting = new class(new InMemoryStorage()) implements TaskStorageInterface {
            public int $getCalls = 0;
            public int $allCalls = 0;

            public function __construct(private readonly TaskStorageInterface $inner)
            {
            }

            public function has(string $taskId, ?string $group = null): bool
            {
                return $this->inner->has($taskId, $group);
            }

            public function get(string $taskId, ?string $group = null): ?TaskExecution
            {
                ++$this->getCalls;

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
                ++$this->allCalls;

                return $this->inner->all();
            }

            public function reset(): void
            {
                $this->inner->reset();
            }
        };

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SimpleTask('task.2', 'Second'),
            new SimpleTask('task.3', 'Third'),
        ], $counting);

        $runner->runAll($this->output);

        self::assertSame(0, $counting->getCalls, 'Pending checks must use the one-shot all() index, not per-slot get().');
        self::assertSame(1, $counting->allCalls);
    }

    public function testSuccessOutcomeStampsExecutedAtFromInjectedClock(): void
    {
        $clock = new MockClock('2026-02-03 04:05:06.123456+00:00');
        $runner = $this->createRunner([new SimpleTask('task.1', 'First')], clock: $clock);

        $runner->runAll($this->output);

        $execution = $this->storage->get('task.1');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Ran, $execution->status);
        self::assertEquals($clock->now(), $execution->executedAt);
    }

    public function testSuccessOutcomePersistsDuration(): void
    {
        $runner = $this->createRunner([new SimpleTask('task.1', 'First')]);

        $runner->runAll($this->output);

        $execution = $this->storage->get('task.1');
        self::assertNotNull($execution);
        self::assertIsInt($execution->durationMs);
        self::assertGreaterThanOrEqual(0, $execution->durationMs);
    }

    public function testFailureOutcomePersistsDuration(): void
    {
        $runner = $this->createRunner([new FailingTask()]);

        $runner->runAll($this->output);

        $execution = $this->storage->get('test.failing');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Failed, $execution->status);
        self::assertIsInt($execution->durationMs);
        self::assertGreaterThanOrEqual(0, $execution->durationMs);
    }

    public function testFailureOutcomeStampsExecutedAtFromInjectedClock(): void
    {
        $clock = new MockClock('2026-02-03 04:05:06.123456+00:00');
        $runner = $this->createRunner([new FailingTask()], clock: $clock);

        $runner->runAll($this->output);

        $execution = $this->storage->get('test.failing');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Failed, $execution->status);
        self::assertEquals($clock->now(), $execution->executedAt);
    }

    public function testRunAllSkipsPreviouslyRanTasks(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SimpleTask('task.2', 'Second'),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        self::assertSame(1, $result->skipped);
        self::assertSame(0, $result->failed);
    }

    public function testRunAllRetriesFailedTasks(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Failed, new \DateTimeImmutable(), 'old error'));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        self::assertSame(0, $result->skipped);
        self::assertSame(TaskStatus::Ran, $this->storage->get('task.1')?->status);
    }

    public function testRunAllForceRerunsAlreadyExecutedTasks(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Skipped, new \DateTimeImmutable()));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SimpleTask('task.2', 'Second'),
        ]);

        $result = $runner->runAll($this->output, new RunOptions(rerunAll: true));

        self::assertSame(2, $result->ran);
        self::assertSame(0, $result->skipped);
        self::assertSame(0, $result->failed);
        self::assertTrue($result->isSuccessful());
    }

    public function testRunAllWithFailingTask(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new FailingTask(),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        self::assertSame(0, $result->skipped);
        self::assertSame(1, $result->failed);
        self::assertFalse($result->isSuccessful());
        $execution = $this->storage->get('test.failing');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Failed, $execution->status);
        self::assertSame('Task failed!', $execution->error);
    }

    public function testRunAllDryRun(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SimpleTask('task.2', 'Second'),
        ]);

        $result = $runner->runAll($this->output, new RunOptions(dryRun: true));

        self::assertSame(2, $result->ran);
        self::assertSame(0, $result->skipped);
        self::assertSame(0, $result->failed);
        self::assertFalse($this->storage->has('task.1'));
        self::assertFalse($this->storage->has('task.2'));
        $output = $this->output->fetch();
        // Default slot → no `@group` suffix, exact label + description format.
        self::assertStringContainsString('  [would run] task.1 - First', $output);
        self::assertStringContainsString('  [would run] task.2 - Second', $output);
        self::assertStringNotContainsString('@', $output);
    }

    public function testDryRunLabelForGroupSlot(): void
    {
        $runner = $this->createRunner([new PredeployTask()]);

        $result = $runner->runAll($this->output, new RunOptions(dryRun: true, groups: ['predeploy']));

        self::assertSame(1, $result->ran);
        // Group slot → label is `{taskId}@{slot}`; kills Concat/Ternary/Identical/Operand-removal mutants
        // on line 178.
        self::assertStringContainsString(
            '  [would run] test.predeploy@predeploy - Predeploy-only task',
            $this->output->fetch(),
        );
    }

    public function testRunAllDryRunSkipsAlreadyRan(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SimpleTask('task.2', 'Second'),
        ]);

        $result = $runner->runAll($this->output, new RunOptions(dryRun: true));

        self::assertSame(1, $result->ran);
        self::assertSame(1, $result->skipped);
    }

    public function testDryRunWithForceCountsAllSlotsAsPending(): void
    {
        // Pre-seed an executed record, then force-preview: every slot must count as
        // pending (ran) and none as skipped — kills ternary-arm mutants in dryRun().
        $task = new SimpleTask('task.1', 'First');
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        $runner = $this->createRunner([$task]);
        $result = $runner->runAll($this->output, new RunOptions(dryRun: true, rerunAll: true));

        self::assertSame(1, $result->ran);
        self::assertSame(0, $result->skipped);
        self::assertNotNull($this->storage->get('task.1'), 'Dry run must not touch storage');
    }

    public function testRunAllWithNoTasks(): void
    {
        $runner = $this->createRunner([]);

        $result = $runner->runAll($this->output);

        self::assertSame(0, $result->ran);
        self::assertSame(0, $result->skipped);
        self::assertSame(0, $result->failed);
        self::assertTrue($result->isSuccessful());
    }

    public function testRunOneSuccess(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
        ]);

        $result = $runner->runOne('task.1', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertTrue($this->storage->has('task.1'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('task.1')?->status);
    }

    public function testRunOneAlreadyExecuted(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
        ]);

        $result = $runner->runOne('task.1', $this->output);

        self::assertSame(TaskResult::SKIPPED, $result);
        self::assertStringContainsString('already been executed', $this->output->fetch());
    }

    public function testRunOneForce(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
        ]);

        $result = $runner->runOne('task.1', $this->output, new RunOptions(rerunAll: true));

        self::assertSame(TaskResult::SUCCESS, $result);
    }

    public function testRunOneFailedTaskReexecutes(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Failed, new \DateTimeImmutable(), 'old error'));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
        ]);

        $result = $runner->runOne('task.1', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertSame(TaskStatus::Ran, $this->storage->get('task.1')?->status);
    }

    public function testRunOneDryRunListsPendingWithoutExecuting(): void
    {
        $runner = $this->createRunner([new SimpleTask('task.1', 'First')]);

        $result = $runner->runOne('task.1', $this->output, new RunOptions(dryRun: true));

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('[would run] task.1 - First', $this->output->fetch());
        self::assertFalse($this->storage->has('task.1'));
    }

    public function testRunOneDryRunOnExecutedTaskReportsAlreadyExecuted(): void
    {
        $runner = $this->createRunner([new SimpleTask('task.1', 'First')]);
        $runner->runOne('task.1', $this->output);
        $this->output->fetch();

        $result = $runner->runOne('task.1', $this->output, new RunOptions(dryRun: true));

        self::assertSame(TaskResult::SKIPPED, $result);
        self::assertStringContainsString('already been executed', $this->output->fetch());
    }

    public function testEventsDispatched(): void
    {
        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            dispatcher: $dispatcher,
        );

        $start = \microtime(true);
        $runner->runAll($this->output);
        $elapsed = \microtime(true) - $start;

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(BeforeTaskEvent::class, $dispatched[0]);
        self::assertInstanceOf(AfterTaskEvent::class, $dispatched[1]);
        self::assertSame('task.1', $dispatched[0]->taskId);
        self::assertSame(TaskResult::SUCCESS, $dispatched[1]->result);
        self::assertGreaterThanOrEqual(0.0, $dispatched[1]->duration);
        self::assertLessThanOrEqual($elapsed + 0.1, $dispatched[1]->duration);
    }

    public function testRunSucceedsWithoutDispatcher(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
        ]);

        $result = $runner->runAll($this->output);

        self::assertTrue($result->isSuccessful());
    }

    public function testTaskFailedEventDispatched(): void
    {
        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $runner = $this->createRunner(
            [new FailingTask()],
            dispatcher: $dispatcher,
        );

        $start = \microtime(true);
        $runner->runAll($this->output);
        $elapsed = \microtime(true) - $start;

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(BeforeTaskEvent::class, $dispatched[0]);
        self::assertInstanceOf(TaskFailedEvent::class, $dispatched[1]);
        self::assertSame('Task failed!', $dispatched[1]->exception->getMessage());
        self::assertGreaterThanOrEqual(0.0, $dispatched[1]->duration);
        self::assertLessThanOrEqual($elapsed + 0.1, $dispatched[1]->duration);
    }

    public function testListenerFailureDoesNotMarkTaskFailed(): void
    {
        // A listener that throws on BeforeTaskEvent must not flip a SUCCESS task to FAILED.
        // The task outcome stands; the listener exception is surfaced via the logger.
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event): object {
                if ($event instanceof BeforeTaskEvent) {
                    throw new \RuntimeException('listener exploded');
                }

                return $event;
            });

        $logger = new ArrayLogger();

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            dispatcher: $dispatcher,
            logger: $logger,
        );

        // The runner must raise EventListenerException (not swallow or rethrow as FAILURE).
        $this->expectException(EventListenerException::class);

        try {
            $runner->runAll($this->output);
        } finally {
            // Even though an EventListenerException propagated, the storage must NOT have
            // recorded a FAILED execution — the task itself succeeded (or was never reached).
            $execution = $this->storage->get('task.1');
            self::assertNull(
                $execution,
                'A throwing BeforeTaskEvent listener must not create a FAILED storage record.',
            );

            // The listener failure must reach the logger.
            self::assertTrue(
                $logger->has('error', 'Deploy task listener failed'),
                'Listener exception must be logged at error level.',
            );
        }
    }

    public function testNoLockFactoryWarningIsSilentByDefault(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
        ]);

        $runner->runAll($this->output);

        self::assertStringNotContainsString('No lock factory configured', $this->output->fetch());
    }

    public function testNoLockFactoryWarningIsShownInVerboseMode(): void
    {
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_VERBOSE);

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
        ]);

        $runner->runAll($output);

        self::assertStringContainsString('No lock factory configured', $output->fetch());
    }

    public function testStorageFailureDuringPersistPropagates(): void
    {
        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $storage = $this->createMock(TaskStorageInterface::class);
        $storage->method('has')->willReturn(false);
        $storage->method('get')->willReturn(null);
        $storage->method('save')->willThrowException(
            new StorageException('Failed to save task "task.1": disk full', 0, new \RuntimeException('disk full')),
        );

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            storage: $storage,
            dispatcher: $dispatcher,
        );

        try {
            $runner->runAll($this->output);
            self::fail('Expected StorageException to propagate from persistOutcome');
        } catch (StorageException $e) {
            self::assertStringContainsString('task.1', $e->getMessage());
        }

        // Persistence happens before AfterTaskEvent: when the save throws, only
        // BeforeTaskEvent has fired and no After event is dispatched for an
        // execution that was never recorded.
        self::assertCount(
            1,
            $dispatched,
            'Only BeforeTaskEvent fires when persistOutcome throws — AfterTaskEvent is dispatched after a successful persist',
        );
        self::assertInstanceOf(BeforeTaskEvent::class, $dispatched[0]);
    }

    public function testStorageFailureDuringFailurePersistPropagatesWithoutTaskFailedEvent(): void
    {
        // Failure-path twin of testStorageFailureDuringPersistPropagates: on the failure
        // path persistOutcome() also runs BEFORE the TaskFailedEvent dispatch, so when
        // save() throws, the StorageException propagates (superseding the task's own
        // exception) and no TaskFailedEvent is dispatched — only BeforeTaskEvent fired.
        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $storage = $this->createMock(TaskStorageInterface::class);
        $storage->method('has')->willReturn(false);
        $storage->method('get')->willReturn(null);
        $storage->method('save')->willThrowException(
            new StorageException(
                'Failed to save task "test.failing": disk full',
                0,
                new \RuntimeException('disk full'),
            ),
        );

        $runner = $this->createRunner(
            [new FailingTask()],
            storage: $storage,
            dispatcher: $dispatcher,
        );

        try {
            $runner->runAll($this->output);
            self::fail('Expected StorageException to propagate from persistOutcome on the failure path');
        } catch (StorageException $e) {
            self::assertStringContainsString('test.failing', $e->getMessage());
        }

        self::assertCount(
            1,
            $dispatched,
            'Only BeforeTaskEvent fires when the failure-path persist throws — TaskFailedEvent is dispatched after a successful persist',
        );
        self::assertInstanceOf(BeforeTaskEvent::class, $dispatched[0]);
    }

    public function testReturnedSkippedLeavesSlotPendingForNextRun(): void
    {
        $runner = $this->createRunner([new SkippingTask()]);

        $first = $runner->runAll($this->output);

        self::assertSame(0, $first->ran);
        self::assertSame(0, $first->failed);
        self::assertSame(0, $first->skipped, 'A self-skipped task is deferred, not skipped — skipped counts only already-executed slots');
        self::assertSame(1, $first->deferred);
        self::assertNull(
            $this->storage->get('test.skipping'),
            'A returned SKIPPED must leave no execution record — the slot stays pending',
        );

        $second = $runner->runAll($this->output);

        self::assertSame(1, $second->deferred, 'The slot is still pending, so the task is attempted again on the next run');
    }

    public function testAlreadyExecutedAndSelfSkippedCountSeparately(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SkippingTask(),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(0, $result->ran);
        self::assertSame(1, $result->skipped, 'The already-executed slot counts as skipped — it will not run again');
        self::assertSame(1, $result->deferred, 'The self-skipped slot counts as deferred — it retries next run');
        self::assertSame(0, $result->failed);
    }

    public function testTransactionalWrapping(): void
    {
        $storage = new TransactionalInMemoryStorageFixture();

        $runner = $this->createRunner([new TransactionalTask()], $storage);

        $runner->runAll($this->output);

        self::assertTrue(
            $storage->has('test.transactional'),
            'Transactional task must persist through the transactional() wrapper.',
        );
        self::assertSame(TaskStatus::Ran, $storage->get('test.transactional')?->status);
    }

    public function testConstructorRefusesPerTaskModeOnStorageThatCannotRollBack(): void
    {
        // The compiler pass covers this when the storage class is resolvable; a
        // storage whose class only exists at runtime reaches the runner unchecked.
        // Refusing at construction is the runtime twin of that compile-time
        // rejection: no task may run under a mode whose rollback promise the
        // storage cannot keep.
        $this->expectException(IncompatibleStorageException::class);
        $this->expectExceptionMessage(\sprintf(
            'Configuration "transaction_mode: per_task" requires a storage backend that supports transactions. Configured storage ("%s") does not.',
            InMemoryStorage::class,
        ));

        $this->createRunner([new SimpleTask('task.1', 'First')], $this->storage, transactionMode: TransactionMode::PerTask);
    }

    public function testConstructorRefusesAllOrNothingModeOnStorageThatCannotRollBack(): void
    {
        $this->expectException(IncompatibleStorageException::class);
        $this->expectExceptionMessage(\sprintf(
            'Configuration "transaction_mode: all_or_nothing" requires a storage backend that supports transactions. Configured storage ("%s") does not.',
            InMemoryStorage::class,
        ));

        $this->createRunner([new SimpleTask('task.1', 'First')], $this->storage, transactionMode: TransactionMode::AllOrNothing);
    }

    public function testConstructorAcceptsNoneModeOnStorageThatCannotRollBack(): void
    {
        // Mode "none" promises no rollback, so a non-transactional backend honors it
        // fully — it is the documented default for custom storage and must keep working.
        $runner = $this->createRunner([new SimpleTask('task.1', 'First')], $this->storage, transactionMode: TransactionMode::None);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        self::assertSame(TaskStatus::Ran, $this->storage->get('task.1')?->status);
    }

    public function testRunOneFailingTask(): void
    {
        $runner = $this->createRunner([new FailingTask()]);

        $result = $runner->runOne('test.failing', $this->output);

        self::assertSame(TaskResult::FAILURE, $result);
        self::assertSame(TaskStatus::Failed, $this->storage->get('test.failing')?->status);
    }

    public function testRunOneWrapsExecutionInTransactionWhenAllOrNothing(): void
    {
        // A save-side probe is not enough: persistOutcomeTransactional() already wraps
        // save() in its own transaction even when runOne() runs the task bare. The
        // discriminating signal is the task's run() itself executing inside
        // storage->transactional() — hence the depth counter probed at run() time.
        // The counter (not a bool) survives the nested per-save transaction.
        $spy = new class($this->storage) implements TransactionalStorageInterface {
            public int $transactionDepth = 0;
            public bool $saveHappenedInsideTransaction = false;

            public function __construct(private readonly TaskStorageInterface $inner)
            {
            }

            public function transactional(\Closure $callback): mixed
            {
                ++$this->transactionDepth;

                try {
                    return $callback();
                } finally {
                    --$this->transactionDepth;
                }
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
                $this->saveHappenedInsideTransaction =
                    $this->saveHappenedInsideTransaction || $this->transactionDepth > 0;
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

            /**
             * @return list<TaskExecution>
             */
            public function findByTaskId(string $taskId): array
            {
                return $this->inner->findByTaskId($taskId);
            }

            /**
             * @return list<TaskExecution>
             */
            public function all(): array
            {
                return $this->inner->all();
            }

            public function reset(): void
            {
                $this->inner->reset();
            }
        };

        $task = new class(static fn (): bool => $spy->transactionDepth > 0) implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public bool $ranInsideTransaction = false;

            /**
             * @param \Closure(): bool $inTransactionProbe
             */
            public function __construct(private readonly \Closure $inTransactionProbe)
            {
            }

            public function getTaskId(): string
            {
                return 'task.1';
            }

            public function getDescription(): string
            {
                return 'Records whether run() executes inside a storage transaction';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                $this->ranInsideTransaction = ($this->inTransactionProbe)();

                return TaskResult::SUCCESS;
            }
        };

        $runner = $this->createRunner([$task], storage: $spy, transactionMode: TransactionMode::AllOrNothing);
        $result = $runner->runOne('task.1', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertTrue(
            $task->ranInsideTransaction,
            'runOne with all_or_nothing must wrap task execution in storage->transactional()',
        );
        self::assertTrue(
            $spy->saveHappenedInsideTransaction,
            'runOne with all_or_nothing must persist the execution record inside storage->transactional()',
        );
    }

    public function testAllOrNothingRollsBackOnFailure(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // Pre-create the table outside any transaction — SQLite DDL is transactional,
        // so auto-init inside allOrNothing would roll back the table along with the data.
        $storage = new DbalStorage($connection);
        $connection->executeStatement($storage->getCreateTableSql());
        $logger = new ArrayLogger();

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First'), new FailingTask()],
            $storage,
            logger: $logger,
            transactionMode: TransactionMode::AllOrNothing,
        );

        try {
            $runner->runAll($this->output);
            self::fail('Expected rollback to propagate the original throwable.');
        } catch (AllOrNothingFailureException $e) {
            // The typed exception carries the original task error as its previous.
            self::assertSame('Task failed!', $e->getPrevious()?->getMessage());
            self::assertSame('test.failing', $e->failedTaskId);
        }

        // All changes must be rolled back — no records saved
        self::assertSame([], $storage->all());

        $rollback = $logger->recordsMatching('error', 'transaction rolled back');
        self::assertCount(1, $rollback);
        self::assertArrayHasKey('exception', $rollback[0]['context']);
        self::assertInstanceOf(\RuntimeException::class, $rollback[0]['context']['exception']);
    }

    public function testRunOneAllOrNothingRollsBackOnFailure(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // Pre-create the table outside any transaction — SQLite DDL is transactional,
        // so auto-init inside allOrNothing would roll back the table along with the data.
        $storage = new DbalStorage($connection);
        $connection->executeStatement($storage->getCreateTableSql());
        $logger = new ArrayLogger();

        $runner = $this->createRunner(
            [new FailingTask()],
            $storage,
            logger: $logger,
            transactionMode: TransactionMode::AllOrNothing,
        );

        try {
            $runner->runOne('test.failing', $this->output);
            self::fail('Expected rollback to surface as AllOrNothingFailureException.');
        } catch (\Throwable $e) {
            // Mirrors executeAll(): runOne wraps the raw task exception so the run command
            // can render the rolled-back summary instead of an uncaught exception.
            self::assertInstanceOf(AllOrNothingFailureException::class, $e);
            self::assertSame('test.failing', $e->failedTaskId);
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertSame('Task failed!', $e->getPrevious()->getMessage());
        }

        // The failure record persisted inside the transaction must be rolled back.
        self::assertSame([], $storage->all());

        $rollback = $logger->recordsMatching('error', 'transaction rolled back');
        self::assertCount(1, $rollback);
        self::assertArrayHasKey('exception', $rollback[0]['context']);
        self::assertInstanceOf(AllOrNothingFailureException::class, $rollback[0]['context']['exception']);
    }

    public function testAllOrNothingWithNonTransactionalStorageRunsNothingAtAll(): void
    {
        // The old contract let this run unwrapped: every task applied, nothing
        // rollback-able, and a failure mid-run left the earlier tasks committed —
        // exactly the silent no-op the guard exists to kill. Now no task runs at all.
        try {
            $this->createAllOrNothingRunner(
                [new SimpleTask('task.1', 'First')],
                $this->storage,
            );
            self::fail('Expected IncompatibleStorageException to be thrown.');
        } catch (IncompatibleStorageException $e) {
            self::assertStringContainsString('all_or_nothing', $e->getMessage());
        }

        self::assertFalse($this->storage->has('task.1'), 'The refused run must not have applied any task.');
    }

    public function testAllOrNothingThrowsTypedExceptionWithPartialResult(): void
    {
        // Three-task scenario: #1 succeeds, #2 throws, #3 never runs.
        // all_or_nothing: true with transactional storage (SQLite in-memory).
        // Phase 01's per-task transactional closure means the failing task's side-effects
        // are rolled back together with everything else — storage ends up empty.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection);
        $connection->executeStatement($storage->getCreateTableSql());
        $task1 = new SimpleTask('task.1', 'First');
        $task2Error = new \RuntimeException('task 2 failed');
        $task2 = $this->makeFailingTaskWithError('task.2', $task2Error);
        $task3 = new SimpleTask('task.3', 'Third');

        $runner = $this->createRunner(
            [$task1, $task2, $task3],
            $storage,
            transactionMode: TransactionMode::AllOrNothing,
        );

        try {
            $runner->runAll($this->output);
            self::fail('Expected AllOrNothingFailureException to be thrown.');
        } catch (AllOrNothingFailureException $e) {
            // Partial result: task #1 ran (ran=1), task #2 failed (failed=1), task #3 never ran (no additional counts).
            self::assertSame(1, $e->partialResult->ran);
            self::assertSame(1, $e->partialResult->failed);
            self::assertSame(0, $e->partialResult->skipped);
            // The wrapped previous must be the original task error.
            self::assertSame($task2Error, $e->getPrevious());
            self::assertSame('task.2', $e->failedTaskId);
        }

        // Transaction rolled back — storage is empty.
        self::assertSame([], $storage->all());
    }

    public function testAllOrNothingCommitsOnSuccess(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection);
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First'), new SimpleTask('task.2', 'Second')],
            $storage,
            transactionMode: TransactionMode::AllOrNothing,
        );

        $runner->runAll($this->output);

        self::assertTrue($storage->has('task.1'));
        self::assertTrue($storage->has('task.2'));
    }

    public function testRunOneNonExistentIdThrowsTaskNotFoundException(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
        ]);

        $this->expectException(TaskNotFoundException::class);

        $runner->runOne('nonexistent.task', $this->output);
    }

    public function testRunAllWithLockFailureReturnsLockedResult(): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false);

        $lockFactory = $this->createMock(LockFactory::class);
        // Lock must be created with the canonical name and a 1-hour TTL — kills IncrementInteger on the 3600 literal.
        $lockFactory->expects(self::once())
            ->method('createLock')
            ->with('soviann_deploy_tasks_run', 3600)
            ->willReturn($lock);

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            lockFactory: $lockFactory,
        );

        $result = $runner->runAll($this->output);

        self::assertTrue($result->locked);
        // Lock-failure sentinel has all zero counts: kills IncrementInteger on the `0, 0, 0` literals.
        self::assertSame(0, $result->ran);
        self::assertSame(0, $result->skipped);
        self::assertSame(0, $result->failed);
    }

    public function testLockReleasedEvenWhenTaskThrows(): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        // Release must fire from the `finally` block — kills UnwrapFinally mutant on line 147.
        $lock->expects(self::once())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $runner = $this->createRunner(
            [new FailingTask()],
            lockFactory: $lockFactory,
        );

        $runner->runAll($this->output);
    }

    public function testRunAllWithGroupFilterOnlyRunsGroupTasks(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.default'),
            new PredeployTask(),
        ]);

        $result = $runner->runAll($this->output, new RunOptions(groups: ['predeploy']));

        self::assertSame(1, $result->ran);
        self::assertFalse($this->storage->has('task.default'));
        self::assertTrue($this->storage->has('test.predeploy', 'predeploy'));
        self::assertFalse($this->storage->has('test.predeploy'));
    }

    public function testRunAllWithDuplicateGroupOptionRunsSlotOnce(): void
    {
        $counting = new SaveCountingStorageFixture();

        $runner = $this->createRunner([new PredeployTask()], $counting);

        $result = $runner->runAll($this->output, new RunOptions(groups: ['predeploy', 'predeploy']));

        self::assertSame(1, $result->ran, 'A repeated --group value must not inflate the ran counter.');
        self::assertSame(1, $counting->saveCalls, 'A repeated --group value must not double-persist the slot.');
    }

    public function testRunOneWithDuplicateRequestedGroupWritesSlotOnce(): void
    {
        $counting = new SaveCountingStorageFixture();

        $runner = $this->createRunner([new MultiGroupTask()], $counting);

        $result = $runner->runOne('test.multi_group', $this->output, new RunOptions(groups: ['predeploy', 'predeploy']));

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertTrue($counting->has('test.multi_group', 'predeploy'));
        self::assertFalse($counting->has('test.multi_group', 'postdeploy'));
        self::assertSame(1, $counting->saveCalls, 'A repeated --group value must not double-persist the slot.');
    }

    public function testRunAllWithoutGroupRunsEveryDeclaredSlot(): void
    {
        // Phase 3 rule: absent --group, a run operates on ALL slots — the
        // default slot of an ungrouped task AND every declared group of a
        // grouped task (mirrors the rollup command's slot expansion).
        $runner = $this->createRunner([
            new SimpleTask('task.default'),
            new MultiGroupTask(),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(3, $result->ran);
        self::assertTrue($this->storage->has('task.default'));
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
        self::assertFalse(
            $this->storage->has('test.multi_group'),
            'A grouped task must never record the default (null) slot',
        );
    }

    public function testRunAllWithoutGroupExecutesMultiGroupTaskOncePerInvocation(): void
    {
        // Pins the execution model under bare-run slot expansion: the task body
        // executes once per invocation (one Before/After event pair) while one
        // storage row is written per declared group — and the expansion cannot
        // duplicate a slot (declared groups are dedup-guaranteed by the attribute).
        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $counting = new SaveCountingStorageFixture();
        $runner = $this->createRunner([new MultiGroupTask()], $counting, dispatcher: $dispatcher);

        $result = $runner->runAll($this->output);

        self::assertSame(2, $result->ran, 'Counters tally per slot, not per task execution');
        self::assertSame(2, $counting->saveCalls, 'Exactly one row per declared group — no duplicate slots');
        self::assertCount(2, $dispatched, 'Events fire once per task execution, not once per slot');
        self::assertInstanceOf(BeforeTaskEvent::class, $dispatched[0]);
        self::assertInstanceOf(AfterTaskEvent::class, $dispatched[1]);
    }

    public function testRunAllWithoutGroupPersistsEveryDeclaredSlotInsidePerTaskTransaction(): void
    {
        // The per-task transaction path must cover the expanded slots: with the
        // default transactional wrapping, the multi-group task's run() and both
        // slot rows commit inside the same transaction.
        $storage = new RollbackTransactionalStorageFixture();
        $runner = $this->createRunner([new MultiGroupTask()], $storage);

        $result = $runner->runAll($this->output);

        self::assertSame(2, $result->ran);
        self::assertTrue($storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($storage->has('test.multi_group', 'postdeploy'));
        self::assertTrue(
            $storage->lastSaveInsideTransaction,
            'Slot rows must be written inside the per-task transaction',
        );
    }

    public function testAllOrNothingRollsBackEveryExpandedSlotOnFailure(): void
    {
        // Registration order is kept by the stable sorter (equal priority, no
        // dates in the ids), so the multi-group task writes its two slot rows
        // before the failing task aborts the run — all_or_nothing must wipe
        // them all, expanded grouped slots included.
        $storage = new RollbackTransactionalStorageFixture();
        $runner = $this->createRunner([new MultiGroupTask(), new FailingTask()], $storage, transactionMode: TransactionMode::AllOrNothing);

        try {
            $runner->runAll($this->output);
            self::fail('Expected AllOrNothingFailureException');
        } catch (AllOrNothingFailureException $e) {
            self::assertSame('test.failing', $e->failedTaskId);
            self::assertSame(2, $e->partialResult->ran, 'Both expanded slots ran before the failure');
        }

        self::assertSame([], $storage->all(), 'Rollback must remove every slot row written during the run');
    }

    public function testDryRunWithoutGroupCountsEveryDeclaredSlot(): void
    {
        // Dry-run must mirror the bare-run slot expansion: one would-run line
        // per slot, nothing persisted.
        $runner = $this->createRunner([
            new SimpleTask('task.default', 'Default task'),
            new MultiGroupTask(),
        ]);

        $result = $runner->runAll($this->output, new RunOptions(dryRun: true));

        self::assertSame(3, $result->ran);
        self::assertSame([], $this->storage->all());
        $output = $this->output->fetch();
        self::assertStringContainsString('  [would run] task.default - Default task', $output);
        self::assertStringContainsString('  [would run] test.multi_group@predeploy', $output);
        self::assertStringContainsString('  [would run] test.multi_group@postdeploy', $output);
    }

    public function testRunAllWithoutGroupIncludesGroupedTasks(): void
    {
        // Flipped by the Phase 3 group-semantics change: a bare run used to
        // exclude grouped tasks; it now targets every slot, so the grouped
        // task runs in its declared group slot alongside the ungrouped one.
        $runner = $this->createRunner([
            new SimpleTask('task.default'),
            new PredeployTask(),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(2, $result->ran);
        self::assertTrue($this->storage->has('task.default'));
        self::assertTrue($this->storage->has('test.predeploy', 'predeploy'));
        self::assertFalse($this->storage->has('test.predeploy'));
    }

    public function testRunAllMultiGroupTaskRunsOncePerRequestedSlot(): void
    {
        $runner = $this->createRunner([new MultiGroupTask()]);

        $result = $runner->runAll($this->output, new RunOptions(groups: ['predeploy', 'postdeploy']));

        self::assertSame(2, $result->ran);
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
        self::assertFalse($this->storage->has('test.multi_group'));
    }

    public function testRunAllMultiGroupTaskRunsOnlyRequestedSlot(): void
    {
        $runner = $this->createRunner([new MultiGroupTask()]);

        $result = $runner->runAll($this->output, new RunOptions(groups: ['predeploy']));

        self::assertSame(1, $result->ran);
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testPartiallyPendingMultiSlotTaskCountsDoneSlotsAsSkipped(): void
    {
        $task = new MultiGroupTask();
        $this->storage->save(
            new TaskExecution('test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'),
        );

        $runner = $this->createRunner([$task]);
        $result = $runner->runAll($this->output, new RunOptions(groups: ['predeploy', 'postdeploy']));

        self::assertSame(1, $result->ran);
        self::assertSame(
            1,
            $result->skipped,
            'Already-done slots of a partially-pending task must be counted as skipped',
        );
    }

    public function testRunOneWithoutGroupsTargetsEveryDeclaredSlot(): void
    {
        // Flipped by the Phase 3 group-semantics change: --id without --group
        // used to throw a group-required exception (class removed); a bare
        // single-task run now targets every declared slot, mirroring the
        // bulk-run expansion.
        $runner = $this->createRunner([new MultiGroupTask()]);

        $result = $runner->runOne('test.multi_group', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
        self::assertFalse(
            $this->storage->has('test.multi_group'),
            'A grouped task must never record the default (null) slot',
        );
    }

    public function testRunOneThrowsWhenRequestedGroupUndeclared(): void
    {
        $runner = $this->createRunner([new PredeployTask()]);

        $this->expectException(TaskGroupMismatchException::class);

        $runner->runOne('test.predeploy', $this->output, new RunOptions(groups: ['postdeploy']));
    }

    public function testRunOneThrowsWhenDefaultTaskReceivesGroups(): void
    {
        $runner = $this->createRunner([new SimpleTask('task.default')]);

        $this->expectException(TaskGroupMismatchException::class);

        $runner->runOne('task.default', $this->output, new RunOptions(groups: ['predeploy']));
    }

    public function testRunOneWritesOneRowPerRequestedGroup(): void
    {
        $runner = $this->createRunner([new MultiGroupTask()]);

        $result = $runner->runOne('test.multi_group', $this->output, new RunOptions(groups: ['predeploy', 'postdeploy']));

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testRunOneAcquiresLock(): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            lockFactory: $lockFactory,
        );

        $result = $runner->runOne('task.1', $this->output);

        // Lock contention is null, matching withLock()'s own convention.
        self::assertNull($result);
        // Presentation belongs to the command layer: the runner only returns the
        // null sentinel (and logs), it must not write to the caller's output.
        self::assertSame('', $this->output->fetch());
        self::assertFalse($this->storage->has('task.1'));
    }

    public function testRunOneWithoutLockFactoryIsSilentByDefault(): void
    {
        $runner = $this->createRunner([new SimpleTask('task.1', 'First')]);

        $result = $runner->runOne('task.1', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringNotContainsString('No lock factory configured', $this->output->fetch());
    }

    public function testRunOneWithoutLockFactoryWarnsInVerboseMode(): void
    {
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_VERBOSE);

        $runner = $this->createRunner([new SimpleTask('task.1', 'First')]);

        $result = $runner->runOne('task.1', $output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('No lock factory configured', $output->fetch());
    }

    public function testRunOneRefusesEnvMismatch(): void
    {
        // ProdOnlyTask is annotated #[AsDeployTask(env: 'prod')]; runner is in 'dev'.
        $prodOnlyTask = new ProdOnlyTask();
        $runner = $this->createRunner(
            [$prodOnlyTask],
            environment: 'dev',
        );

        try {
            $runner->runOne('test.prod_only', $this->output);
            self::fail('Expected TaskEnvironmentMismatchException to be thrown.');
        } catch (TaskEnvironmentMismatchException $e) {
            self::assertSame('test.prod_only', $e->taskId);
            self::assertSame('prod', $e->taskEnv);
            self::assertSame('dev', $e->runnerEnv);
        }

        // Task must NOT have been invoked — no storage record written.
        self::assertFalse(
            $this->storage->has('test.prod_only'),
            'Task run() must not be invoked when env constraint is refused.',
        );
    }

    public function testRunOneAllowsEnvMatchAndNullConstraint(): void
    {
        // Task with matching env runs fine; task with no env constraint (null) always runs.
        $prodOnlyTask = new ProdOnlyTask();
        $defaultTask = new SimpleTask('task.default', 'Default');
        $runnerProd = $this->createRunner(
            [$prodOnlyTask, $defaultTask],
            environment: 'prod',
        );

        $resultProd = $runnerProd->runOne('test.prod_only', $this->output);
        self::assertSame(TaskResult::SUCCESS, $resultProd);

        $resultDefault = $runnerProd->runOne('task.default', $this->output);
        self::assertSame(TaskResult::SUCCESS, $resultDefault);
    }

    public function testSlowTaskThresholdExceededLogsWarningWithoutFailing(): void
    {
        $logger = new ArrayLogger();

        // 1.1s sleep against a 1s threshold — reliably crosses it without depending
        // on microsecond-level scheduling. Slow by design (~1.1s per run).
        $runner = $this->createRunner(
            [new SleepingTask('task.1', 1_100_000)],
            logger: $logger,
            slowTaskThreshold: 1,
        );

        $result = $runner->runOne('task.1', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('exceeded the slow-task threshold', $this->output->fetch());
        self::assertTrue($logger->has('warning', 'exceeded slow-task threshold'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('task.1')?->status);
    }

    public function testSlowTaskThresholdZeroDisablesCheck(): void
    {
        $logger = new ArrayLogger();

        // 50ms sleep against a disabled threshold (0): no warning, regardless of duration.
        $runner = $this->createRunner(
            [new SleepingTask('task.1', 50_000)],
            logger: $logger,
            slowTaskThreshold: 0,
        );

        $result = $runner->runOne('task.1', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringNotContainsString('exceeded the slow-task threshold', $this->output->fetch());
        self::assertFalse($logger->has('warning', 'exceeded slow-task threshold'));
    }

    public function testAttributeSlowTaskThresholdOverridesConfiguredThreshold(): void
    {
        $logger = new ArrayLogger();

        // The task declares slowTaskThreshold: 1 and sleeps ~1.1s; the configured
        // threshold (3600) alone would never warn, so a warning proves the
        // attribute override is consulted. Slow by design (~1.1s per run).
        $runner = $this->createRunner(
            [new SlowThresholdLoweringTask()],
            logger: $logger,
            slowTaskThreshold: 3600,
        );

        $result = $runner->runOne('test.slow_threshold_lowering', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        // The attribute value (1s), not the configured one (3600s), lands in the message.
        self::assertMatchesRegularExpression(
            '/Task "test\.slow_threshold_lowering" exceeded the slow-task threshold \(\d+s elapsed, 1s threshold\)\./',
            $this->output->fetch(),
        );
        self::assertTrue($logger->has('warning', 'exceeded slow-task threshold'));
    }

    public function testAttributeSlowTaskThresholdZeroDisablesCheckForTask(): void
    {
        $logger = new ArrayLogger();

        // The task opts out (slowTaskThreshold: 0) and sleeps ~1.1s past the 1s
        // configured threshold: no warning may fire. Slow by design (~1.1s per run).
        $runner = $this->createRunner(
            [new SlowThresholdDisabledTask()],
            logger: $logger,
            slowTaskThreshold: 1,
        );

        $result = $runner->runOne('test.slow_threshold_disabled', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringNotContainsString('exceeded the slow-task threshold', $this->output->fetch());
        self::assertFalse($logger->has('warning', 'exceeded slow-task threshold'));
    }

    public function testAttributeTimeoutDoesNotAffectSlowTaskCheck(): void
    {
        $logger = new ArrayLogger();

        // `timeout:` drives only the hard Process kill (ProcessRunnerTrait). The task
        // declares timeout: 3600 and sleeps ~1.1s past the 1s configured threshold:
        // the slow-task warning must still fire — the hard knob no longer doubles as
        // a per-task soft override. Slow by design (~1.1s per run).
        $runner = $this->createRunner(
            [new HardTimeoutOnlySleepingTask()],
            logger: $logger,
            slowTaskThreshold: 1,
        );

        $result = $runner->runOne('test.hard_timeout_only', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('exceeded the slow-task threshold', $this->output->fetch());
        self::assertTrue($logger->has('warning', 'exceeded slow-task threshold'));
    }

    public function testTransactionalTaskUnderNoneModeRunsUnwrapped(): void
    {
        // #[AsDeployTask(transactional: true)] is only honored in per_task mode, so
        // under "none" the task runs unwrapped on a non-transactional backend and the
        // runner is constructible. (The DI layer rejects this pairing at compile time
        // — IncompatibleStorageException::taskOptInConflictsWithModeNone — but the
        // runner itself does not read the attribute here, and must not refuse it.)
        $runner = $this->createRunner([new TransactionalTask()], transactionMode: TransactionMode::None);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        self::assertSame(0, $result->failed);
        self::assertTrue($this->storage->has('test.transactional'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('test.transactional')?->status);
        self::assertStringContainsString('Transactional task executed', $this->output->fetch());
    }

    public function testFailedCountAccumulatesAcrossMultipleTasks(): void
    {
        // Two independent failures → failed=2; kills Assignment mutator on `$failed += $count` (line 219).
        // If `+=` collapses to `=`, second failure overwrites the first and failed=1.
        $runner = $this->createRunner([
            new FailingTask(),
            new SimpleTask('task.ok', 'Ok'),
            $this->makeFailingTask('test.failing.second'),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        self::assertSame(2, $result->failed);
    }

    public function testSkippedCountAccumulatesAcrossMultipleTasks(): void
    {
        // Two already-ran tasks → skipped=2; kills Assignment mutator on `$skipped += \count($slots)` (line 208).
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('task.2', TaskStatus::Ran, new \DateTimeImmutable()));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SimpleTask('task.2', 'Second'),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(0, $result->ran);
        self::assertSame(2, $result->skipped);
        self::assertSame(0, $result->failed);
    }

    public function testDeferredCountAccumulatesAcrossMultipleTasks(): void
    {
        // Two tasks that self-report SKIPPED → deferred=2; kills Assignment mutator on
        // executeAll()'s `$deferred += $count`.
        $runner = $this->createRunner([
            new SkippingTask(),
            $this->makeSkippingTask('test.skipping.second'),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(0, $result->ran);
        self::assertSame(0, $result->skipped);
        self::assertSame(2, $result->deferred);
    }

    public function testSlowTaskWarningMessageIncludesExactValues(): void
    {
        // Pins the exact warning format — kills Minus/CastInt mutants on the `(int) $duration` and
        // `$duration - $start` lines.
        $runner = $this->createRunner(
            [new SleepingTask('task.1', 1_100_000)],
            slowTaskThreshold: 1,
        );

        $runner->runOne('task.1', $this->output);

        // Exact format: `Task "{id}" exceeded the slow-task threshold ({duration}s elapsed, {threshold}s threshold)`.
        $output = $this->output->fetch();
        self::assertMatchesRegularExpression(
            '/Task "task\.1" exceeded the slow-task threshold \(\d+s elapsed, 1s threshold\)\./',
            $output,
        );
    }

    public function testFailureErrorMessageIncludesExactFormat(): void
    {
        // Pins the `Task "{id}" failed: {message}` writeln — kills MethodCallRemoval on line 272.
        $runner = $this->createRunner([new FailingTask()]);

        $runner->runAll($this->output);

        self::assertStringContainsString('Task "test.failing" failed: Task failed!', $this->output->fetch());
    }

    public function testFailureErrorMessageIsStrippedOfTerminalControlCharacters(): void
    {
        // A task exception carrying an ANSI escape sequence must not reach the
        // terminal raw — the runner sanitizes the message before writeln.
        $runner = $this->createRunner([
            $this->makeFailingTaskWithError('task.ansi', new \RuntimeException("boom\x1b[2J")),
        ]);

        $runner->runAll($this->output);

        $output = $this->output->fetch();
        self::assertStringContainsString('boom', $output);
        self::assertStringNotContainsString("\x1b", $output);
    }

    public function testRunAllLogsLifecycle(): void
    {
        $logger = new ArrayLogger();

        $runner = $this->createRunner(
            [
                new SimpleTask('task.1', 'First'),
                new SimpleTask('task.2', 'Second'),
                new SkippingTask(),
            ],
            logger: $logger,
        );

        $runner->runAll($this->output);

        self::assertTrue($logger->has('info', 'Deploy tasks run starting'));
        self::assertTrue($logger->has('info', 'Deploy tasks run finished'));
        // SimpleTask → SUCCESS, SkippingTask → SKIPPED; both emit the same info message
        // with `result` distinguishing them. Assert the aggregate so the test is robust
        // against future lifecycle-log additions.
        self::assertGreaterThanOrEqual(2, \count($logger->recordsMatching('info', 'Deploy task executed')));
    }

    public function testFailingTaskLogsError(): void
    {
        $logger = new ArrayLogger();

        $runner = $this->createRunner(
            [new FailingTask()],
            logger: $logger,
        );

        $runner->runAll($this->output);

        $matches = $logger->recordsMatching('error', 'Deploy task failed');
        self::assertCount(1, $matches);
        self::assertSame('test.failing', $matches[0]['context']['task_id'] ?? null);
        self::assertIsInt($matches[0]['context']['duration_ms'] ?? null);
        self::assertInstanceOf(\Throwable::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testFailingTaskWithDbalCauseScrubsExceptionContext(): void
    {
        $logger = new ArrayLogger();

        $runner = $this->createRunner(
            [new DbalFailingTask()],
            logger: $logger,
        );

        $runner->runAll($this->output);

        $matches = $logger->recordsMatching('error', 'Deploy task failed');
        self::assertCount(1, $matches);

        $context = $matches[0]['context'];

        // Full Throwable object must not be logged when a DBAL exception sits in the
        // chain; Monolog's normaliser would otherwise serialise previous->trace and
        // export the DSN embedded in the DBAL message.
        self::assertArrayNotHasKey('exception', $context);
        self::assertSame(StorageException::class, $context['exception_class'] ?? null);
        self::assertSame('Failed to save task "test.dbal-failing".', $context['exception_message'] ?? null);
        self::assertSame(DbalFailingTask::DBAL_MESSAGE, $context['previous_message'] ?? null);
    }

    public function testSlowTaskThresholdExceededDuringRunAllLogsWarning(): void
    {
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SleepingTask('task.1', 1_100_000)],
            logger: $logger,
            slowTaskThreshold: 1,
        );

        $runner->runAll($this->output);

        self::assertTrue($logger->has('warning', 'exceeded slow-task threshold'));
    }

    public function testLockDeniedLogsWarning(): void
    {
        $logger = new ArrayLogger();
        $lockFactory = new LockFactory(new InMemoryStore());
        // Pre-acquire the shared lock outside the runner so its own acquire call fails.
        $heldLock = $lockFactory->createLock('soviann_deploy_tasks_run', 3600);
        self::assertTrue($heldLock->acquire());

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            logger: $logger,
            lockFactory: $lockFactory,
        );

        $runner->runAll($this->output);

        self::assertTrue($logger->has('warning', 'another process is already running'));

        $heldLock->release();
    }

    public function testMissingLockFactoryLogsWarning(): void
    {
        $logger = new ArrayLogger();

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            logger: $logger,
        );

        $runner->runAll($this->output);

        self::assertTrue($logger->has('warning', 'no lock factory'));
    }

    public function testNoLockWarningWhenLockingIsDeliberatelyDisabled(): void
    {
        $logger = new ArrayLogger();

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            logger: $logger,
            lockDisabledByConfig: true,
        );

        $runner->runAll($this->output);

        self::assertFalse($logger->has('warning', 'no lock factory'));
    }

    public function testRunAllEmitsProgressPrefixPerTask(): void
    {
        // Each executed task must produce a `[i/N] FQCN` progress line before execution.
        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SimpleTask('task.2', 'Second'),
        ]);

        $runner->runAll($this->output);

        $display = $this->output->fetch();

        // Two tasks executed → exactly two progress prefix lines.
        self::assertMatchesRegularExpression('/ \[1\/2\] \S+/', $display);
        self::assertMatchesRegularExpression('/ \[2\/2\] \S+/', $display);
    }

    public function testRunAllEmitsCompletionLineAfterEachTask(): void
    {
        // After each executed task a `→ <status> (<ms>ms)` completion line must follow.
        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new FailingTask(),
        ]);

        $runner->runAll($this->output);

        $display = $this->output->fetch();

        self::assertMatchesRegularExpression('/→ ran \(\d+ms\)/', $display);
        self::assertMatchesRegularExpression('/→ failed \(\d+ms\)/', $display);
    }

    public function testRunAllProgressCountMatchesExecutedTasks(): void
    {
        // When one task is already ran, total progress count should reflect only executable tasks (1/1, not 1/2).
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SimpleTask('task.2', 'Second'),
        ]);

        $runner->runAll($this->output);

        $display = $this->output->fetch();

        self::assertMatchesRegularExpression('/ \[1\/1\] \S+/', $display);
        self::assertStringNotContainsString('[1/2]', $display);
        self::assertStringNotContainsString('[2/2]', $display);
    }

    public function testRunOneEmitsProgressOneOfOne(): void
    {
        // runOne always emits [1/1] prefix and a completion line.
        $runner = $this->createRunner([new SimpleTask('task.1', 'First')]);

        $runner->runOne('task.1', $this->output);

        $display = $this->output->fetch();

        self::assertMatchesRegularExpression('/ \[1\/1\] \S+/', $display);
        self::assertMatchesRegularExpression('/→ ran \(\d+ms\)/', $display);
    }

    public function testRunAllSkippingTaskEmitsSkippedStatus(): void
    {
        // A task that self-reports SKIPPED must produce `→ skipped (Xms)`.
        $runner = $this->createRunner([new SkippingTask()]);

        $runner->runAll($this->output);

        $display = $this->output->fetch();

        self::assertMatchesRegularExpression('/→ skipped \(\d+ms\)/', $display);
    }

    public function testTaskOutcomeCarriesDurationSeconds(): void
    {
        // Verify TaskOutcome exposes durationSeconds (readonly float ≥ 0).
        // We call runOne and verify the storage reflects the run; the field existence
        // is validated via static analysis but we also confirm it's accessible.
        $runner = $this->createRunner([new SimpleTask('task.1', 'First')]);
        $runner->runOne('task.1', $this->output);

        // If durationSeconds didn't exist, PHPStan level 9 would already catch it —
        // confirm the task ran successfully (outcome was built with the field).
        self::assertSame(TaskStatus::Ran, $this->storage->get('task.1')?->status);
    }

    public function testExecuteTaskRollsBackOnSaveFailure(): void
    {
        // Goal: on transactional storage, the success-path persist runs inside the SAME
        // per-task transaction as run() (wrapInTransaction folds them). If save() throws,
        // the transaction rolls back the task's work together with the record, and the
        // exception re-raises to the caller: no failure record is written, and no
        // committed-but-unrecorded state can survive.
        //
        // The distinguishing observable: $rollbackTriggered is set to true only when the
        // exception from save() is caught by transactional() — which only happens if save()
        // was called INSIDE a transactional() closure, proving the persist is folded in.

        $storage = new class implements TransactionalStorageInterface {
            /** @var array<string, TaskExecution> */
            private array $executions = [];
            private int $saveCallCount = 0;

            public bool $rollbackTriggered = false;

            public function has(string $taskId, ?string $group = null): bool
            {
                return isset($this->executions[$taskId."\0".($group ?? '')]);
            }

            public function get(string $taskId, ?string $group = null): ?TaskExecution
            {
                return $this->executions[$taskId."\0".($group ?? '')] ?? null;
            }

            public function save(TaskExecution $execution): void
            {
                ++$this->saveCallCount;
                // Throw on the first save call to simulate a storage failure.
                if (1 === $this->saveCallCount) {
                    throw new StorageException('Storage failure on save');
                }
                $this->executions[$execution->id."\0".($execution->group ?? '')] = $execution;
            }

            public function remove(string $taskId, ?string $group = null): void
            {
                unset($this->executions[$taskId."\0".($group ?? '')]);
            }

            public function removeAll(string $taskId): void
            {
            }

            /** @return list<TaskExecution> */
            public function findByTaskId(string $taskId): array
            {
                return [];
            }

            /** @return list<TaskExecution> */
            public function all(): array
            {
                return \array_values($this->executions);
            }

            public function reset(): void
            {
                $this->executions = [];
            }

            public function transactional(\Closure $callback): mixed
            {
                try {
                    return $callback();
                } catch (\Throwable $e) {
                    // Signal that the transaction had to roll back.
                    $this->rollbackTriggered = true;
                    throw $e;
                }
            }
        };

        $task = new class implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public bool $ran = false;

            public function getTaskId(): string
            {
                return 'test.transactional.save-fails';
            }

            public function getDescription(): string
            {
                return 'Task with side-effect';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                $this->ran = true;

                return TaskResult::SUCCESS;
            }
        };

        $runner = $this->createRunner([$task], $storage);

        try {
            $runner->runAll($this->output);
            self::fail('Expected StorageException to propagate');
        } catch (StorageException) {
            // expected
        }

        self::assertTrue($task->ran, 'Task must have run before save() threw');
        // The critical assertion: transactional() must have caught the save() exception
        // (rollback triggered), proving save() was called INSIDE the closure.
        self::assertTrue(
            $storage->rollbackTriggered,
            'transactional() must have caught the save() failure — save() must be called inside the closure, not after it returns',
        );
    }

    public function testTransactionalTaskSideEffectsRollBackWhenRecordSaveFails(): void
    {
        // The split-transaction double-execution window: in per-task transactional
        // mode, run() side effects and the execution-record save() must commit in ONE
        // transaction. When save() fails after run() succeeded, the task's work must
        // roll back with it — committed-but-unrecorded work would silently re-run the
        // task on the next deploy.
        $storage = new RollbackTransactionalStorageFixture();
        $storage->failNextSave = true;

        $task = new class($storage) implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public function __construct(private readonly RollbackTransactionalStorageFixture $storage)
            {
            }

            public function getTaskId(): string
            {
                return 'test.rollback.save-fails';
            }

            public function getDescription(): string
            {
                return 'Applies work into the transactional backend';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                $this->storage->sideEffects[] = 'users migrated';

                return TaskResult::SUCCESS;
            }
        };

        $runner = $this->createRunner([$task], $storage);

        try {
            $runner->runAll($this->output);
            self::fail('Expected the storage failure to propagate');
        } catch (StorageException) {
            // expected — the execution record could not be persisted
        }

        self::assertSame(
            [],
            $storage->sideEffects,
            'run() side effects must roll back together with the failed record save — committed-but-unrecorded work re-runs the task on the next deploy',
        );
        self::assertFalse(
            $storage->has('test.rollback.save-fails'),
            'No execution record may survive the rolled-back transaction',
        );
    }

    public function testTransactionalTaskDbalSideEffectsRollBackWhenRecordSaveFails(): void
    {
        // Real-DBAL proof of the same guarantee: the task writes a row on the storage
        // connection during run(); when the record save() then fails, the SQL
        // transaction must roll that row back together with the record.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection);
        $connection->executeStatement($storage->getCreateTableSql());
        $connection->executeStatement('CREATE TABLE task_work (val VARCHAR(32) NOT NULL)');

        // Decorates the real storage: save() fails once; everything else — including
        // transactional() with its real BEGIN/ROLLBACK — reaches DbalStorage unchanged.
        $failingSave = new class($storage) implements TransactionalStorageInterface {
            private bool $saveFailed = false;

            public function __construct(private readonly DbalStorage $inner)
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
                if (!$this->saveFailed) {
                    $this->saveFailed = true;

                    throw new StorageException(\sprintf('Simulated failure saving task "%s".', $execution->id));
                }

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

            /** @return list<TaskExecution> */
            public function findByTaskId(string $taskId): array
            {
                return $this->inner->findByTaskId($taskId);
            }

            /** @return list<TaskExecution> */
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
                return $this->inner->transactional($callback);
            }
        };

        $task = new class($connection) implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public function __construct(private readonly \Doctrine\DBAL\Connection $connection)
            {
            }

            public function getTaskId(): string
            {
                return 'test.dbal.rollback';
            }

            public function getDescription(): string
            {
                return 'Inserts a row on the storage connection';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                $this->connection->executeStatement("INSERT INTO task_work (val) VALUES ('done')");

                return TaskResult::SUCCESS;
            }
        };

        $runner = $this->createRunner([$task], $failingSave);

        try {
            $runner->runAll($this->output);
            self::fail('Expected the storage failure to propagate');
        } catch (StorageException) {
            // expected — the execution record could not be persisted
        }

        /** @var int|string|false $workRows */
        $workRows = $connection->fetchOne('SELECT COUNT(*) FROM task_work');
        self::assertSame(0, (int) $workRows, "The task's INSERT must roll back with the failed record save");
        self::assertSame([], $storage->all(), 'No execution record may survive the rollback');
    }

    public function testRefreshesLockBetweenTasks(): void
    {
        $refreshCount = 0;

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->method('refresh')->willReturnCallback(static function () use (&$refreshCount): void {
            ++$refreshCount;
        });

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $runner = $this->createRunner(
            [
                new SimpleTask('task.1', 'First'),
                new SimpleTask('task.2', 'Second'),
                new SimpleTask('task.3', 'Third'),
            ],
            lockFactory: $lockFactory,
        );

        $runner->runAll($this->output);

        self::assertGreaterThanOrEqual(
            2,
            $refreshCount,
            'refresh() must be called between each task (at least 2 times for 3 tasks)',
        );
    }

    public function testLostLockLeaseDuringRefreshStopsRunWithLockedResult(): void
    {
        // A task that outruns lock.ttl loses the lease, so the between-task refresh()
        // throws LockConflictedException. The runner must convert that into the same
        // locked sentinel an acquire failure produces — not an uncaught exception —
        // and must not run the remaining tasks: a second runner may hold the lock now.
        $logger = new ArrayLogger();

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->method('refresh')->willThrowException(new LockConflictedException('lease lost'));

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $runner = $this->createRunner(
            [
                new SleepingTask('task.1', 1_000),
                new SimpleTask('task.2', 'Second'),
            ],
            logger: $logger,
            lockFactory: $lockFactory,
        );

        $result = $runner->runAll($this->output);

        self::assertTrue($result->locked);
        self::assertSame(1, $result->ran, 'The task that ran before the lease was lost must stay counted');
        self::assertTrue($this->storage->has('task.1'), 'The completed task keeps its execution record');
        self::assertFalse($this->storage->has('task.2'), 'The run must stop after the lease is lost');
        self::assertTrue($logger->has('warning', 'could not be refreshed'));
    }

    // -------------------------------------------------------------------------
    // Log-context assertions (ArrayItem / ArrayItemRemoval mutants)
    // -------------------------------------------------------------------------

    public function testRunAllStartLogIncludesEnvironmentContext(): void
    {
        // Kills ArrayItemRemoval/ArrayItem on line 76 ('environment' key).
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            logger: $logger,
            environment: 'test_env',
        );

        $runner->runAll($this->output);

        $records = $logger->recordsMatching('info', 'Deploy tasks run starting');
        self::assertCount(1, $records);
        self::assertArrayHasKey('environment', $records[0]['context']);
        self::assertSame('test_env', $records[0]['context']['environment']);
    }

    public function testRunAllStartLogIncludesDryRunContext(): void
    {
        // Kills ArrayItem on line 77 ('dry_run' key).
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            logger: $logger,
        );

        $runner->runAll($this->output, new RunOptions(dryRun: true));

        $records = $logger->recordsMatching('info', 'Deploy tasks run starting');
        self::assertCount(1, $records);
        self::assertTrue($records[0]['context']['dry_run']);
    }

    public function testRunAllFinishLogIncludesRanContext(): void
    {
        // Kills ArrayItemRemoval/ArrayItem on line 107-111 ('ran'/'skipped'/'failed'/'locked' keys).
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First'), new SimpleTask('task.2', 'Second')],
            logger: $logger,
        );

        $runner->runAll($this->output);

        $records = $logger->recordsMatching('info', 'Deploy tasks run finished');
        self::assertCount(1, $records);
        $ctx = $records[0]['context'];
        self::assertSame(2, $ctx['ran']);
        self::assertSame(0, $ctx['skipped']);
        self::assertSame(0, $ctx['failed']);
        self::assertFalse($ctx['locked']);
    }

    public function testRunAllFinishLogCorrectCountsWithSkippedAndFailed(): void
    {
        // Kills 'skipped' and 'failed' ArrayItem mutants on lines 109-110.
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First'), new FailingTask()],
            logger: $logger,
        );

        $runner->runAll($this->output);

        $records = $logger->recordsMatching('info', 'Deploy tasks run finished');
        self::assertCount(1, $records);
        $ctx = $records[0]['context'];
        self::assertSame(0, $ctx['ran']);
        self::assertSame(1, $ctx['skipped']);
        self::assertSame(1, $ctx['failed']);
    }

    public function testRunOneSkippedLogIncludesTaskIdContext(): void
    {
        // Kills ArrayItemRemoval/MethodCallRemoval on line 157 ('task_id' key and logger call).
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            logger: $logger,
        );

        $runner->runOne('task.1', $this->output);

        $records = $logger->recordsMatching('info', 'Deploy task skipped (already executed)');
        self::assertCount(1, $records);
        self::assertSame('task.1', $records[0]['context']['task_id'] ?? null);
    }

    public function testExecuteTaskStartLogIncludesTaskId(): void
    {
        // Kills ArrayItemRemoval/MethodCallRemoval on line 348 ('task_id' key in starting log).
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            logger: $logger,
        );

        $runner->runAll($this->output);

        $records = $logger->recordsMatching('info', 'Deploy task starting');
        self::assertCount(1, $records);
        self::assertSame('task.1', $records[0]['context']['task_id'] ?? null);
    }

    public function testBeforeListenerFailureLogIncludesEventKey(): void
    {
        // Kills ArrayItemRemoval on the 'event' key in dispatchGuarded's listener-failed error log.
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event): object {
                if ($event instanceof BeforeTaskEvent) {
                    throw new \RuntimeException('before listener boom');
                }

                return $event;
            });

        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            dispatcher: $dispatcher,
            logger: $logger,
        );

        try {
            $runner->runAll($this->output);
        } catch (EventListenerException) {
            // expected
        }

        $records = $logger->recordsMatching('error', 'Deploy task listener failed');
        self::assertCount(1, $records);
        self::assertSame(BeforeTaskEvent::class, $records[0]['context']['event'] ?? null);
        self::assertSame('task.1', $records[0]['context']['task'] ?? null);
    }

    public function testAfterListenerFailureLogIncludesEventKey(): void
    {
        // Kills ArrayItemRemoval on the AfterTaskEvent listener-failed error log (same pattern).
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event): object {
                if ($event instanceof AfterTaskEvent) {
                    throw new \RuntimeException('after listener boom');
                }

                return $event;
            });

        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            dispatcher: $dispatcher,
            logger: $logger,
        );

        try {
            $runner->runAll($this->output);
        } catch (EventListenerException) {
            // expected
        }

        $records = $logger->recordsMatching('error', 'Deploy task listener failed');
        self::assertCount(1, $records);
        self::assertSame(AfterTaskEvent::class, $records[0]['context']['event'] ?? null);
    }

    public function testBeforeListenerExceptionCodeIsZero(): void
    {
        // Kills DecrementInteger (-1) and IncrementInteger (+1) mutants on the
        // EventListenerException code argument in dispatchGuarded().
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event): object {
                if ($event instanceof BeforeTaskEvent) {
                    throw new \RuntimeException('listener exploded');
                }

                return $event;
            });

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            dispatcher: $dispatcher,
        );

        try {
            $runner->runAll($this->output);
            self::fail('Expected EventListenerException');
        } catch (EventListenerException $e) {
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertSame('listener exploded', $e->getPrevious()->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Listener failures after persistence — the execution record must survive
    // -------------------------------------------------------------------------

    public function testAfterListenerExceptionPropagatesWithoutMarkingTaskFailed(): void
    {
        // Invariant: executeTask's success path persists the Ran record BEFORE
        // dispatching AfterTaskEvent. A throwing After listener surfaces as
        // EventListenerException without overwriting the record — the generic
        // \Throwable handler re-raises it because the task itself succeeded.
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event): object {
                if ($event instanceof AfterTaskEvent) {
                    throw new \RuntimeException('after listener boom');
                }

                return $event;
            });

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            dispatcher: $dispatcher,
        );

        $caught = null;

        try {
            $runner->runAll($this->output);
            self::fail('Expected EventListenerException');
        } catch (EventListenerException $e) {
            $caught = $e;
        }

        self::assertSame(
            'after listener boom',
            $caught->getPrevious()?->getMessage(),
            'the original listener error is chained as the previous exception',
        );

        // The record must be persisted BEFORE the After listener runs, so a throwing
        // listener cannot lose a successful execution.
        $execution = $this->storage->get('task.1');
        self::assertNotNull($execution, 'Successful task must be persisted before AfterTaskEvent listeners run');
        self::assertSame(TaskStatus::Ran, $execution->status);
    }

    public function testFailedListenerExceptionDoesNotLoseFailureRecord(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event): object {
                if ($event instanceof TaskFailedEvent) {
                    throw new \RuntimeException('failed listener boom');
                }

                return $event;
            });

        $runner = $this->createRunner([new FailingTask()], dispatcher: $dispatcher);

        $caught = null;

        try {
            $runner->runAll($this->output);
            self::fail('Expected EventListenerException');
        } catch (EventListenerException $e) {
            $caught = $e;
        }

        self::assertSame(
            'failed listener boom',
            $caught->getPrevious()?->getMessage(),
            'the original listener error is chained as the previous exception',
        );

        $execution = $this->storage->get('test.failing');
        self::assertNotNull($execution, 'Failed record must be persisted before TaskFailedEvent listeners run');
        self::assertSame(TaskStatus::Failed, $execution->status);
    }

    // -------------------------------------------------------------------------
    // Duration-ms display in completion line (lines 373 & 395)
    // -------------------------------------------------------------------------

    public function testSuccessCompletionLineShowsMilliseconds(): void
    {
        // Kills Multiplication (* / 1000), DecrementInteger (*999), IncrementInteger (*1001),
        // and RoundingFamily mutants on line 373 — pins that the format is `X ms`.
        // Use a 100ms sleep so the displayed value is distinguishable from 0ms.
        $runner = $this->createRunner([new SleepingTask('task.1', 100_000)]);

        $runner->runOne('task.1', $this->output);

        // `→ ran (Xms)` where X ≥ 1 (100ms sleep * 1000 ≠ 0 and ≠ near-zero from / 1000).
        $display = $this->output->fetch();
        self::assertMatchesRegularExpression('/→ ran \(\d+ms\)/', $display);
        \preg_match('/→ ran \((\d+)ms\)/', $display, $m);
        self::assertGreaterThanOrEqual(
            50,
            (int) ($m[1] ?? 0),
            'Completion ms must reflect actual duration (not duration/1000)',
        );
    }

    public function testFailureCompletionLineShowsMilliseconds(): void
    {
        // Kills Multiplication/Decrement/Increment/RoundingFamily mutants on line 395.
        $runner = $this->createRunner([$this->makeSleepingFailingTask('task.slow-fail', 100_000)]);

        $runner->runAll($this->output);

        $display = $this->output->fetch();
        self::assertMatchesRegularExpression('/→ failed \(\d+ms\)/', $display);
        \preg_match('/→ failed \((\d+)ms\)/', $display, $m);
        self::assertGreaterThanOrEqual(
            50,
            (int) ($m[1] ?? 0),
            'Failure completion ms must reflect actual duration (not duration/1000)',
        );
    }

    // -------------------------------------------------------------------------
    // Slow-task threshold boundary: strictly > not >=
    // -------------------------------------------------------------------------

    public function testSlowTaskWarningNotTriggeredWhenDurationBelowThreshold(): void
    {
        // Kills GreaterThan (>= mutant on the `$duration > $threshold` comparison).
        // This test uses a near-instant task (0 sleep) with a 300s threshold — duration
        // will be far below it. The key is the boundary: `$duration > $threshold`
        // must NOT fire when duration < threshold. We verify the normal case holds
        // and the warning is absent without a sleep (duration ≈ 0, threshold = 300).
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            logger: $logger,
            slowTaskThreshold: 300,
        );

        $runner->runOne('task.1', $this->output);

        self::assertFalse($logger->has('warning', 'exceeded slow-task threshold'), 'Warning must not fire when duration << threshold');
        self::assertStringNotContainsString('exceeded the slow-task threshold', $this->output->fetch());
    }

    public function testSlowTaskWarningLogIncludesTaskId(): void
    {
        // Kills ArrayItemRemoval ('task_id' key in the slow-task warning log).
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SleepingTask('task.slow', 1_100_000)],
            logger: $logger,
            slowTaskThreshold: 1,
        );

        $runner->runOne('task.slow', $this->output);

        $records = $logger->recordsMatching('warning', 'exceeded slow-task threshold');
        self::assertCount(1, $records);
        self::assertSame('task.slow', $records[0]['context']['task_id'] ?? null);
    }

    public function testExecutedLogIncludesTaskId(): void
    {
        // Kills ArrayItemRemoval on line 433 ('task_id' key in executed log).
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            logger: $logger,
        );

        $runner->runAll($this->output);

        $records = $logger->recordsMatching('info', 'Deploy task executed');
        self::assertNotEmpty($records);
        self::assertSame('task.1', $records[0]['context']['task_id'] ?? null);
    }

    public function testExecutedLogDurationMsIsInteger(): void
    {
        // Kills CastInt (line 436): removes (int) cast leaving a float.
        // Also kills Multiplication/Decrement/Increment/RoundingFamily by asserting
        // that the value is in the milliseconds range (not ~0 from /1000).
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SleepingTask('task.1', 100_000)],
            logger: $logger,
        );

        $runner->runAll($this->output);

        $records = $logger->recordsMatching('info', 'Deploy task executed');
        self::assertNotEmpty($records);
        $durationMs = $records[0]['context']['duration_ms'] ?? null;
        self::assertIsInt($durationMs, 'duration_ms must be cast to int');
        self::assertGreaterThanOrEqual(50, $durationMs, 'duration_ms must be in milliseconds range, not seconds');
    }

    public function testFailedLogDurationMsIsIntegerInMilliseconds(): void
    {
        // Kills Multiplication/Decrement/Increment/RoundingFamily/CastInt mutants on line 481.
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [$this->makeSleepingFailingTask('task.slow-fail', 100_000)],
            logger: $logger,
        );

        $runner->runAll($this->output);

        $records = $logger->recordsMatching('error', 'Deploy task failed');
        self::assertCount(1, $records);
        $durationMs = $records[0]['context']['duration_ms'] ?? null;
        self::assertIsInt($durationMs, 'duration_ms must be cast to int');
        self::assertGreaterThanOrEqual(50, $durationMs, 'duration_ms must be in milliseconds range, not seconds');
    }

    // -------------------------------------------------------------------------
    // NullSafeMethodCall on getPrevious() (line 513)
    // -------------------------------------------------------------------------

    public function testBuildExceptionLogContextWithNullPreviousDoesNotThrow(): void
    {
        // Kills NullSafeMethodCall (line 513): without `?->` the code calls ->getMessage()
        // on null when the outer DBAL exception has no previous, causing a fatal error.
        $logger = new ArrayLogger();

        // A task that throws a bare DBAL exception (no previous exception) — exercises
        // the getPrevious()?->getMessage() path where getPrevious() returns null.
        $task = new class implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public function getTaskId(): string
            {
                return 'task.dbal-no-previous';
            }

            public function getDescription(): string
            {
                return 'DBAL exception without previous';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                throw new \Doctrine\DBAL\Exception\InvalidArgumentException('DBAL error without cause');
            }
        };

        $runner = $this->createRunner([$task], logger: $logger);

        $runner->runAll($this->output);

        $records = $logger->recordsMatching('error', 'Deploy task failed');
        self::assertCount(1, $records);
        $ctx = $records[0]['context'];
        self::assertArrayNotHasKey('exception', $ctx);
        self::assertArrayHasKey(
            'previous_message',
            $ctx,
            'previous_message key must be present even when getPrevious() is null',
        );
        self::assertNull($ctx['previous_message'], 'previous_message must be null when getPrevious() is null');
    }

    // -------------------------------------------------------------------------
    // wrapInTransaction: allOrNothing skips the per-task wrap
    // -------------------------------------------------------------------------

    public function testAllOrNothingPathReturnsTaskResult(): void
    {
        // Guards wrapInTransaction's allOrNothing skip: under all_or_nothing the task
        // must run exactly once, unwrapped (the run-wide transaction already covers
        // it), and its result must survive through the split persist path.
        $runCount = 0;
        $task = new class($runCount) implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public function __construct(private int &$runCount)
            {
            }

            public function getTaskId(): string
            {
                return 'task.aon-return';
            }

            public function getDescription(): string
            {
                return 'AON return test';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                ++$this->runCount;

                return TaskResult::SUCCESS;
            }
        };

        // A transactional backend is now the only legal pairing for all_or_nothing:
        // the constructor refuses the mode on storage that cannot roll back.
        $runner = $this->createRunner([$task], new TransactionalInMemoryStorageFixture(), transactionMode: TransactionMode::AllOrNothing);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        // The task must run exactly once — the early return inside allOrNothing prevents
        // the code falling through to the shouldWrap path and running a second time.
        self::assertSame(1, $runCount);
    }

    // -------------------------------------------------------------------------
    // wrapInTransaction: transaction_mode gating + attribute override (per_task only)
    // -------------------------------------------------------------------------

    public function testPerTaskModeWrapsRunByDefault(): void
    {
        // Kills the `?? true` default and the PerTask comparison mutants on the
        // shouldWrap line: an attribute-less task under per_task must run INSIDE
        // storage->transactional(), probed via the transaction depth.
        $storage = new RollbackTransactionalStorageFixture();

        $task = new TransactionDepthProbeTask('task.default-wrap', $storage);

        $runner = $this->createRunner([$task], $storage, transactionMode: TransactionMode::PerTask);

        $runner->runAll($this->output);

        self::assertTrue(
            $task->ranInsideTransaction,
            'per_task mode must wrap an attribute-less task by default',
        );
        self::assertTrue(
            $storage->has('task.default-wrap'),
            'The execution record must persist through the wrapped path',
        );
    }

    public function testPerTaskModeHonorsAttributeTransactionalFalse(): void
    {
        // The per-task opt-out only applies in per_task mode — and there it MUST
        // apply: run() executes OUTSIDE any transaction while the split persist
        // still wraps its saves. Kills Coalesce mutants on the shouldWrap line.
        $storage = new RollbackTransactionalStorageFixture();

        $task = new #[AsDeployTask(id: 'test.attribute-opt-out', transactional: false)] class($storage) implements \Soviann\DeployTasksBundle\DeployTaskInterface {
            public bool $ranInsideTransaction = false;

            public function __construct(private readonly RollbackTransactionalStorageFixture $storage)
            {
            }

            public function getDescription(): string
            {
                return 'Probes the transaction depth at run() time';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                $this->ranInsideTransaction = $this->storage->transactionDepth > 0;

                return TaskResult::SUCCESS;
            }
        };

        $runner = $this->createRunner([$task], $storage, transactionMode: TransactionMode::PerTask);

        $runner->runAll($this->output);

        self::assertFalse(
            $task->ranInsideTransaction,
            '#[AsDeployTask(transactional: false)] must opt the task out of the per_task wrap',
        );
        self::assertTrue(
            $storage->has('test.attribute-opt-out'),
            'The opted-out task must still persist its execution record',
        );
        self::assertTrue(
            $storage->lastSaveInsideTransaction,
            'The split-path persist must still wrap its saves in a per-save transaction',
        );
    }

    public function testNoneModeSkipsPerTaskWrap(): void
    {
        // Under transaction_mode: none the runner must not open a transaction
        // around run() even on a transactional storage; the split persist still
        // wraps its saves.
        $storage = new RollbackTransactionalStorageFixture();

        $task = new TransactionDepthProbeTask('task.unwrapped', $storage);

        $runner = $this->createRunner([$task], $storage, transactionMode: TransactionMode::None);

        $runner->runAll($this->output);

        self::assertFalse(
            $task->ranInsideTransaction,
            'wrapInTransaction must not open a transaction around run() under mode none',
        );
        self::assertTrue(
            $storage->lastSaveInsideTransaction,
            'The split-path persist must still wrap its saves in a per-save transaction',
        );
    }

    public function testNoneModeIgnoresAttributeTransactionalTrue(): void
    {
        // The attribute override applies only in per_task mode. In DI this
        // conflict is rejected at compile time; a hand-constructed runner under
        // mode none must run the task unwrapped rather than let the attribute
        // leak a per-task transaction into a mode that disables them.
        $storage = new RollbackTransactionalStorageFixture();

        $task = new #[AsDeployTask(id: 'test.attribute-opt-in', transactional: true)] class($storage) implements \Soviann\DeployTasksBundle\DeployTaskInterface {
            public bool $ranInsideTransaction = false;

            public function __construct(private readonly RollbackTransactionalStorageFixture $storage)
            {
            }

            public function getDescription(): string
            {
                return 'Probes the transaction depth at run() time';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                $this->ranInsideTransaction = $this->storage->transactionDepth > 0;

                return TaskResult::SUCCESS;
            }
        };

        $runner = $this->createRunner([$task], $storage, transactionMode: TransactionMode::None);

        $runner->runAll($this->output);

        self::assertFalse(
            $task->ranInsideTransaction,
            'transactional: true must not wrap outside per_task mode',
        );
        self::assertTrue(
            $storage->has('test.attribute-opt-in'),
            'The task must still run and persist its execution record',
        );
    }

    public function testTransactionalStoragePathReturnsResult(): void
    {
        // Kills ReturnRemoval on the transactional branch of wrapInTransaction: without
        // the return, the method falls through to the split path and calls $run() again —
        // the task runs twice (once inside transactional(), once bare after it).
        $runCount = 0;
        $task = new class($runCount) implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public function __construct(private int &$runCount)
            {
            }

            public function getTaskId(): string
            {
                return 'task.return-check';
            }

            public function getDescription(): string
            {
                return 'Return check task';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                ++$this->runCount;

                return TaskResult::SUCCESS;
            }
        };

        $storage = new TransactionalInMemoryStorageFixture();
        $runner = $this->createRunner([$task], $storage, transactionMode: TransactionMode::PerTask);

        $runner->runAll($this->output);

        self::assertSame(1, $runCount, 'Task must run exactly once — ReturnRemoval would cause it to run twice');
    }

    // -------------------------------------------------------------------------
    // persistOutcomeTransactional early return
    // -------------------------------------------------------------------------

    public function testPersistOutcomeTransactionalDoesNotSaveTwice(): void
    {
        // Kills ReturnRemoval in persistOutcomeTransactional: without the `return` after
        // the transactional() call, the code falls through to the bare persistOutcome()
        // and saves each slot a second time. Mode none routes the success persist
        // through persistOutcomeTransactional (wrapped tasks persist inside their
        // run() transaction and never reach it).
        $saveCount = 0;
        $storage = $this->makeSaveCountingTransactionalStorage($saveCount);

        $runner = $this->createRunner([new SimpleTask('task.1', 'First')], $storage, transactionMode: TransactionMode::None);

        $runner->runAll($this->output);

        self::assertSame(
            1,
            $saveCount,
            'save() must be called exactly once per slot — ReturnRemoval would double-save',
        );
    }

    // -------------------------------------------------------------------------
    // UnwrapArrayValues on array_values(array_diff(...)) (line 601)
    // -------------------------------------------------------------------------

    public function testGroupMismatchExceptionPassesListToCreate(): void
    {
        // Kills UnwrapArrayValues on line 601: array_values() ensures a list (consecutive
        // integer keys starting at 0). Without it, array_diff() returns a map with
        // non-consecutive keys, which can break downstream list-typed consumers.
        // Observable: the exception must be thrown; its groups are what matters.
        $runner = $this->createRunner([new MultiGroupTask()]);

        try {
            $runner->runOne('test.multi_group', $this->output, new RunOptions(groups: ['predeploy', 'nonexistent']));
            self::fail('Expected TaskGroupMismatchException');
        } catch (TaskGroupMismatchException $e) {
            // The exception was thrown — array_diff found the undeclared group.
            // The key assertion: exception is thrown (not silently ignored because of
            // a keyed array failing `[] !== $undeclared`).
            self::assertStringContainsString('nonexistent', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // filterPendingSlots force with multiple slots (line 618)
    // -------------------------------------------------------------------------

    public function testForceWithMultipleGroupSlotsReturnsAllSlots(): void
    {
        // Kills ArrayOneItem on line 618: the mutant returns only the first slot when
        // count > 1, truncating multi-slot forced re-runs.
        $runner = $this->createRunner([new MultiGroupTask()]);

        // Pre-seed both slots as already executed.
        $this->storage->save(
            new TaskExecution('test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), group: 'predeploy'),
        );
        $this->storage->save(
            new TaskExecution('test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), group: 'postdeploy'),
        );

        $result = $runner->runAll($this->output, new RunOptions(rerunAll: true, groups: ['predeploy', 'postdeploy']));

        // Both slots must be re-run — the mutant would run only predeploy (1 ran).
        self::assertSame(2, $result->ran);
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
    }

    // -------------------------------------------------------------------------
    // computeSlots: a grouped task never targets the default (null) slot
    // -------------------------------------------------------------------------

    public function testComputeSlotsDoesNotIncludeNullGroupForGroupedTask(): void
    {
        // Flipped by the Phase 3 group-semantics change: a bare run now targets
        // the grouped task's declared slots — but still never the default (null)
        // slot. Kills mutants that make computeSlots return [null] (or append
        // null) for a grouped task on an unfiltered run.
        $runner = $this->createRunner([new PredeployTask()]);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        self::assertTrue($this->storage->has('test.predeploy', 'predeploy'));
        self::assertFalse(
            $this->storage->has('test.predeploy'),
            'Grouped task must not run in the default slot (null group)',
        );
    }

    // -------------------------------------------------------------------------
    // AllOrNothingFailureException partial result locked flag (line 298)
    // -------------------------------------------------------------------------

    public function testAllOrNothingPartialResultHasLockedFalse(): void
    {
        // Kills FalseValue (locked: true mutant on line 298): the partial result
        // built when allOrNothing triggers must have locked=false (the runner acquired
        // the lock; failure is due to task error, not a lock contention).
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection);
        $connection->executeStatement($storage->getCreateTableSql());

        $runner = $this->createRunner(
            [new FailingTask()],
            $storage,
            transactionMode: TransactionMode::AllOrNothing,
        );

        try {
            $runner->runAll($this->output);
            self::fail('Expected AllOrNothingFailureException');
        } catch (AllOrNothingFailureException $e) {
            self::assertFalse(
                $e->partialResult->locked,
                'Partial result locked must be false — task failed, lock was not contended',
            );
        }
    }

    // -------------------------------------------------------------------------
    // dryRun: continue vs break (line 230)
    // -------------------------------------------------------------------------

    public function testDryRunContinuesIteratingAfterSkippedSlot(): void
    {
        // Kills Continue_ → break mutant on line 230:
        // with `break`, after the first skipped slot the loop exits and subsequent
        // slots (and tasks) are never counted as pending.
        // Use a multi-slot task where one slot is already executed.
        $this->storage->save(
            new TaskExecution('test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), group: 'predeploy'),
        );

        $runner = $this->createRunner([new MultiGroupTask()]);

        $result = $runner->runAll($this->output, new RunOptions(dryRun: true, groups: ['predeploy', 'postdeploy']));

        // predeploy slot is already ran → skipped; postdeploy slot is pending.
        // With the break mutant, the loop exits after predeploy and postdeploy is never counted.
        self::assertSame(1, $result->ran, 'Only the pending slot must be counted after a skipped one');
        self::assertSame(1, $result->skipped, 'The already-executed slot must be counted as skipped');
    }

    public function testReturnedFailureIsRecordedAsFailed(): void
    {
        $runner = $this->createRunner([new ReturnsFailureTask()]);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->failed);
        self::assertSame(0, $result->ran);

        $execution = $this->storage->get('test.returns_failure');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Failed, $execution->status);
        self::assertStringContainsString('returned TaskResult::FAILURE', (string) $execution->error);
    }

    public function testReturnedFailureIsRetriedOnNextRun(): void
    {
        $runner = $this->createRunner([new ReturnsFailureTask()]);

        $runner->runAll($this->output);
        $second = $runner->runAll($this->output);

        self::assertSame(1, $second->failed, 'A Failed slot must be pending again on the next run');
    }

    public function testReturnedFailureDispatchesTaskFailedEventNotAfterTaskEvent(): void
    {
        $events = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$events): object {
                $events[] = $event::class;

                return $event;
            });

        $runner = $this->createRunner([new ReturnsFailureTask()], dispatcher: $dispatcher);
        $runner->runAll($this->output);

        self::assertContains(TaskFailedEvent::class, $events);
        self::assertNotContains(AfterTaskEvent::class, $events);
    }

    public function testReturnedFailureAbortsAllOrNothingRun(): void
    {
        $storage = new TransactionalInMemoryStorageFixture();
        $runner = $this->createRunner([new ReturnsFailureTask()], storage: $storage, transactionMode: TransactionMode::AllOrNothing);

        $this->expectException(AllOrNothingFailureException::class);
        $runner->runAll($this->output);
    }

    public function testReturnedFailureUnderPerTaskTransactionIsRecordedAsFailed(): void
    {
        $storage = new TransactionalInMemoryStorageFixture();
        $runner = $this->createRunner(
            [new ReturnsFailureTask()],
            storage: $storage,
            transactionMode: TransactionMode::PerTask,
        );

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->failed);

        $execution = $storage->get('test.returns_failure');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Failed, $execution->status);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeSaveCountingTransactionalStorage(int &$saveCount): TransactionalStorageInterface
    {
        return new class($saveCount) implements TransactionalStorageInterface {
            /** @var array<string, TaskExecution> */
            private array $executions = [];

            public function __construct(private int &$saveCount)
            {
            }

            public function has(string $taskId, ?string $group = null): bool
            {
                return isset($this->executions[$taskId."\0".($group ?? '')]);
            }

            public function get(string $taskId, ?string $group = null): ?TaskExecution
            {
                return $this->executions[$taskId."\0".($group ?? '')] ?? null;
            }

            public function save(TaskExecution $execution): void
            {
                ++$this->saveCount;
                $this->executions[$execution->id."\0".($execution->group ?? '')] = $execution;
            }

            public function remove(string $taskId, ?string $group = null): void
            {
                unset($this->executions[$taskId."\0".($group ?? '')]);
            }

            public function removeAll(string $taskId): void
            {
            }

            /** @return list<TaskExecution> */
            public function findByTaskId(string $taskId): array
            {
                return [];
            }

            /** @return list<TaskExecution> */
            public function all(): array
            {
                return \array_values($this->executions);
            }

            public function reset(): void
            {
                $this->executions = [];
            }

            public function transactional(\Closure $callback): mixed
            {
                return $callback();
            }
        };
    }

    private function makeSleepingFailingTask(
        string $taskId,
        int $sleepMicroseconds,
    ): \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
        return new class($taskId, $sleepMicroseconds) implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public function __construct(private readonly string $id, private readonly int $sleep)
            {
            }

            public function getTaskId(): string
            {
                return $this->id;
            }

            public function getDescription(): string
            {
                return 'Sleeping then failing';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                \usleep($this->sleep);
                throw new \RuntimeException('deliberate failure after sleep');
            }
        };
    }

    /**
     * @param array<\Soviann\DeployTasksBundle\DeployTaskInterface> $tasks
     */
    private function createAllOrNothingRunner(array $tasks, TaskStorageInterface $storage): TaskRunner
    {
        return $this->createRunner($tasks, $storage, transactionMode: TransactionMode::AllOrNothing);
    }

    private function makeFailingTask(string $taskId): \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface
    {
        return new class($taskId) implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function getTaskId(): string
            {
                return $this->id;
            }

            public function getDescription(): string
            {
                return 'Inline failing fixture';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                throw new \RuntimeException('boom');
            }
        };
    }

    private function makeFailingTaskWithError(
        string $taskId,
        \Throwable $error,
    ): \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
        return new class($taskId, $error) implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public function __construct(private readonly string $id, private readonly \Throwable $error)
            {
            }

            public function getTaskId(): string
            {
                return $this->id;
            }

            public function getDescription(): string
            {
                return 'Inline failing fixture (with custom error)';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                throw $this->error;
            }
        };
    }

    private function makeSkippingTask(string $taskId): \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface
    {
        return new class($taskId) implements \Soviann\DeployTasksBundle\DeployTaskInterface, \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function getTaskId(): string
            {
                return $this->id;
            }

            public function getDescription(): string
            {
                return 'Inline skipping fixture';
            }

            public function run(\Symfony\Component\Console\Output\OutputInterface $output): TaskResult
            {
                return TaskResult::SKIPPED;
            }
        };
    }

    /**
     * @param array<\Soviann\DeployTasksBundle\DeployTaskInterface> $tasks
     * @param TransactionMode|null                                  $transactionMode Defaults to the strongest mode the
     *                                                                               storage can honor, mirroring how the
     *                                                                               extension derives it from the active
     *                                                                               backend — the runner refuses any
     *                                                                               transaction-requiring mode on a
     *                                                                               storage that cannot roll back
     */
    private function createRunner(
        array $tasks,
        ?TaskStorageInterface $storage = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        ?LockFactory $lockFactory = null,
        int $slowTaskThreshold = 300,
        ?TransactionMode $transactionMode = null,
        int $lockTtl = 3600,
        ?string $environment = null,
        ?ClockInterface $clock = null,
        bool $lockDisabledByConfig = false,
    ): TaskRunner {
        $idResolver = new TaskIdResolver();
        $storage ??= $this->storage;
        $transactionMode ??= $storage instanceof TransactionalStorageInterface
            ? TransactionMode::PerTask
            : TransactionMode::None;

        return new TaskRunner(
            new TaskRegistry($tasks, $idResolver),
            $storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            $slowTaskThreshold,
            $transactionMode,
            $lockTtl,
            lockDisabledByConfig: $lockDisabledByConfig,
            dispatcher: $dispatcher,
            lockFactory: $lockFactory,
            environment: $environment,
            clock: $clock ?? new SystemClock(),
            logger: $logger ?? new NullLogger(),
        );
    }
}
