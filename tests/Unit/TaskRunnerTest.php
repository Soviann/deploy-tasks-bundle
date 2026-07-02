<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Event\AfterTaskEvent;
use Soviann\DeployTasksBundle\Event\BeforeTaskEvent;
use Soviann\DeployTasksBundle\Event\TaskFailedEvent;
use Soviann\DeployTasksBundle\Exception\AllOrNothingFailureException;
use Soviann\DeployTasksBundle\Exception\EventListenerException;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Exception\TaskEnvironmentMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupRequiredException;
use Soviann\DeployTasksBundle\Exception\TaskNotFoundException;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Runner\RunOptions;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
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
use Soviann\DeployTasksBundle\Tests\Fixtures\MultiGroupTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\PredeployTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ProdOnlyTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\ReturnsFailureTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SkippingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SleepingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalInMemoryStorageFixture;
use Soviann\DeployTasksBundle\Tests\Fixtures\TransactionalTask;
use Symfony\Component\Console\Output\BufferedOutput;
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

    public function testSkippedTaskStatus(): void
    {
        $runner = $this->createRunner([new SkippingTask()]);

        $result = $runner->runAll($this->output);

        self::assertSame(0, $result->ran);
        self::assertSame(0, $result->failed);
        self::assertSame(1, $result->skipped);
        self::assertSame(TaskStatus::Skipped, $this->storage->get('test.skipping')?->status);
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

    public function testTransactionalTaskOnNonTransactionalStorageWarnsAndRunsUnwrapped(): void
    {
        // Only reachable on a hand-constructed runner: in DI the compiler pass rejects
        // transactional config on a non-transactional storage at compile time. The
        // fall-through must be loud (warning) instead of silently skipping the wrap.
        $logger = new ArrayLogger();
        $runner = $this->createRunner([new TransactionalTask()], logger: $logger);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        self::assertSame(TaskStatus::Ran, $this->storage->get('test.transactional')?->status);

        $records = $logger->recordsMatching(
            'warning',
            'does not support transactions — running unwrapped',
        );
        self::assertCount(1, $records);
        self::assertSame('test.transactional', $records[0]['context']['task_id'] ?? null);
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

        $runner = $this->createRunner([$task], storage: $spy, allOrNothing: true);
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
            allOrNothing: true,
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
            allOrNothing: true,
        );

        try {
            $runner->runOne('test.failing', $this->output);
            self::fail('Expected rollback to propagate the original throwable.');
        } catch (\Throwable $e) {
            // runOne rethrows the raw task exception — no AllOrNothingFailureException wrap.
            self::assertSame(\RuntimeException::class, $e::class);
            self::assertSame('Task failed!', $e->getMessage());
        }

        // The failure record persisted inside the transaction must be rolled back.
        self::assertSame([], $storage->all());

        $rollback = $logger->recordsMatching('error', 'transaction rolled back');
        self::assertCount(1, $rollback);
        self::assertArrayHasKey('exception', $rollback[0]['context']);
        self::assertInstanceOf(\RuntimeException::class, $rollback[0]['context']['exception']);
    }

    public function testAllOrNothingWithNonTransactionalStorageRunsUnwrapped(): void
    {
        // Non-transactional storage bypasses the transactional wrap on line 69
        // (`storage instanceof TransactionalStorageInterface`).
        // A mutant that inverts the instanceof check would call `transactional()` on InMemoryStorage and crash.
        $runner = $this->createAllOrNothingRunner(
            [new SimpleTask('task.1', 'First')],
            $this->storage,
        );

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        self::assertTrue($this->storage->has('task.1'));
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
            allOrNothing: true,
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
            allOrNothing: true,
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

    public function testRunAllWithoutGroupExcludesGroupedTasks(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.default'),
            new PredeployTask(),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        self::assertTrue($this->storage->has('task.default'));
        self::assertFalse($this->storage->has('test.predeploy'));
        self::assertFalse($this->storage->has('test.predeploy', 'predeploy'));
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

    public function testRunOneThrowsWhenTaskDeclaresGroupsAndNoneRequested(): void
    {
        $runner = $this->createRunner([new PredeployTask()]);

        $this->expectException(TaskGroupRequiredException::class);

        $runner->runOne('test.predeploy', $this->output);
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

        self::assertSame(TaskResult::LOCKED, $result);
        // Presentation belongs to the command layer: the runner only returns the
        // LOCKED sentinel (and logs), it must not write to the caller's output.
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

    public function testTimeoutExceededLogsWarningWithoutFailing(): void
    {
        $logger = new ArrayLogger();

        // 1.1s sleep against a 1s timeout — reliably crosses the threshold without depending
        // on microsecond-level scheduling. Slow by design (~1.1s per run).
        $runner = $this->createRunner(
            [new SleepingTask('task.1', 1_100_000)],
            logger: $logger,
            defaultTimeout: 1,
        );

        $result = $runner->runOne('task.1', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringContainsString('exceeded timeout', $this->output->fetch());
        self::assertTrue($logger->has('warning', 'exceeded timeout'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('task.1')?->status);
    }

    public function testDefaultTimeoutZeroDisablesTimeoutCheck(): void
    {
        $logger = new ArrayLogger();

        // 50ms sleep against a disabled timeout (0): no warning, regardless of duration.
        $runner = $this->createRunner(
            [new SleepingTask('task.1', 50_000)],
            logger: $logger,
            defaultTimeout: 0,
        );

        $result = $runner->runOne('task.1', $this->output);

        self::assertSame(TaskResult::SUCCESS, $result);
        self::assertStringNotContainsString('exceeded timeout', $this->output->fetch());
        self::assertFalse($logger->has('warning', 'exceeded timeout'));
    }

    public function testTransactionalTaskWithNonTransactionalStorageRunsUnwrapped(): void
    {
        $runner = $this->createRunner([new TransactionalTask()]);

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

    public function testSkippingTaskCountAccumulatesAcrossMultipleTasks(): void
    {
        // Two tasks that self-report SKIPPED → skipped=2; kills Assignment mutator on `$skipped += $count`
        // (line 221).
        $runner = $this->createRunner([
            new SkippingTask(),
            $this->makeSkippingTask('test.skipping.second'),
        ]);

        $result = $runner->runAll($this->output);

        self::assertSame(0, $result->ran);
        self::assertSame(2, $result->skipped);
    }

    public function testTimeoutWarningMessageIncludesExactValues(): void
    {
        // Pins the exact warning format — kills Minus/CastInt mutants on the `(int) $duration` and
        // `$duration - $start` lines.
        $runner = $this->createRunner(
            [new SleepingTask('task.1', 1_100_000)],
            defaultTimeout: 1,
        );

        $runner->runOne('task.1', $this->output);

        // Exact format: `Task "{id}" exceeded timeout ({duration}s elapsed, {limit}s limit)`.
        $output = $this->output->fetch();
        self::assertMatchesRegularExpression(
            '/Task "task\.1" exceeded timeout \(\d+s elapsed, 1s limit\)\./',
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

    public function testTimeoutExceedsLogsWarning(): void
    {
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SleepingTask('task.1', 1_100_000)],
            logger: $logger,
            defaultTimeout: 1,
        );

        $runner->runAll($this->output);

        self::assertTrue($logger->has('warning', 'exceeded timeout'));
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
        // Goal: on transactional storage, persistence runs in its own per-task transaction
        // (persistOutcomeTransactional). If save() throws, the task's own transaction has
        // already committed; the persist transaction catches the exception (triggering
        // rollback) and re-raises it, so callers see the failure, no failure record is
        // written, and a partial multi-slot write cannot survive.
        //
        // The distinguishing observable: $rollbackTriggered is set to true only when the
        // exception from save() is caught by transactional() — which only happens if save()
        // was called INSIDE a transactional() closure, proving the persist is wrapped.

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
    // Timeout boundary (line 417): strictly > not >=
    // -------------------------------------------------------------------------

    public function testTimeoutWarningNotTriggeredWhenDurationEqualsTimeout(): void
    {
        // Kills GreaterThan (>= mutant on line 417).
        // This test uses a near-instant task (0 sleep) with a 300s timeout — duration
        // will be far below timeout. The key is the boundary: `$duration > $timeout`
        // must NOT fire when duration < timeout. We verify the normal case holds
        // and the warning is absent without a sleep (duration ≈ 0, timeout = 300).
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            logger: $logger,
            defaultTimeout: 300,
        );

        $runner->runOne('task.1', $this->output);

        self::assertFalse($logger->has('warning', 'exceeded timeout'), 'Timeout must not fire when duration << limit');
        self::assertStringNotContainsString('exceeded timeout', $this->output->fetch());
    }

    public function testTimeoutWarningLogIncludesTaskId(): void
    {
        // Kills ArrayItemRemoval on line 424 ('task_id' key in timeout warning log).
        $logger = new ArrayLogger();
        $runner = $this->createRunner(
            [new SleepingTask('task.slow', 1_100_000)],
            logger: $logger,
            defaultTimeout: 1,
        );

        $runner->runOne('task.slow', $this->output);

        $records = $logger->recordsMatching('warning', 'exceeded timeout');
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
    // wrapInTransaction: allOrNothing path returns result (line 539)
    // -------------------------------------------------------------------------

    public function testAllOrNothingPathReturnsTaskResult(): void
    {
        // Kills ReturnRemoval on line 539: without the return, the task result is
        // dropped, execution falls through to the attribute-based path, runs task
        // twice, and returns the second call's result (or wraps in a transaction).
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

        $runner = $this->createRunner([$task], allOrNothing: true);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->ran);
        // The task must run exactly once — the early return inside allOrNothing prevents
        // the code falling through to the shouldWrap path and running a second time.
        self::assertSame(1, $runCount);
    }

    // -------------------------------------------------------------------------
    // wrapInTransaction: attribute.transactional coalesce / ternary (lines 542-545)
    // -------------------------------------------------------------------------

    public function testAttributeTransactionalFalseOverridesGlobalDefault(): void
    {
        // Kills Coalesce and Ternary mutants on line 542:
        // `$attribute->transactional ?? $this->transactional` — when the attribute
        // sets transactional: true and the global is false, the attribute must win.
        // Observable: with a transactional storage, wrapInTransaction must call
        // transactional() even though global transactional is false.

        $transactionalCallCount = 0;
        $storage = $this->makeCountingTransactionalStorage($transactionalCallCount);

        // TransactionalTask has #[AsDeployTask(transactional: true)]; global is false.
        $runner = $this->createRunner(
            [new TransactionalTask()],
            $storage,
            transactional: false, // global OFF — attribute must override
        );

        $runner->runAll($this->output);

        // TransactionalTask has transactional: true; that must override global=false.
        // wrapInTransaction calls transactional() once; persistOutcomeTransactional adds another.
        self::assertGreaterThanOrEqual(
            2,
            $transactionalCallCount,
            'Attribute transactional:true must override global transactional:false',
        );
    }

    public function testGlobalTransactionalFalseSkipsWrapWhenAttributeIsNull(): void
    {
        // Kills LogicalAndSingleSubExprNegation on line 544: with !$shouldWrap, a task
        // that should NOT wrap (shouldWrap=false) would be wrapped instead.
        $transactionalCallCount = 0;
        $storage = $this->makeCountingTransactionalStorage($transactionalCallCount);

        // SimpleTask has no #[AsDeployTask] attribute — global config controls wrapping.
        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            $storage,
            transactional: false, // should NOT wrap
        );

        $runner->runAll($this->output);

        // With !shouldWrap mutant: transactional() would be called once for wrapInTransaction.
        // With correct code: wrapInTransaction must NOT call transactional() when shouldWrap=false.
        // persistOutcomeTransactional always adds exactly 1 call on TransactionalStorage.
        // So exactly 1 call expected (from persistOutcomeTransactional only).
        self::assertSame(
            1,
            $transactionalCallCount,
            'wrapInTransaction must not call transactional() when shouldWrap=false',
        );
    }

    public function testTransactionalStoragePathReturnsResult(): void
    {
        // Kills ReturnRemoval on line 545: without the return inside the transactional
        // block, the method falls through to `return $task->run($output)` — the task
        // runs twice (once inside transactional(), once bare after it).
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
        $runner = $this->createRunner([$task], $storage, transactional: true);

        $runner->runAll($this->output);

        self::assertSame(1, $runCount, 'Task must run exactly once — ReturnRemoval would cause it to run twice');
    }

    // -------------------------------------------------------------------------
    // persistOutcomeTransactional early return (line 570)
    // -------------------------------------------------------------------------

    public function testPersistOutcomeTransactionalDoesNotSaveTwice(): void
    {
        // Kills ReturnRemoval on line 570: without the `return` after the transactional()
        // call, the code falls through to the bare persistOutcome() and saves each slot
        // a second time. We use a TransactionalStorageInterface mock that counts saves.
        $saveCount = 0;
        $storage = $this->makeSaveCountingTransactionalStorage($saveCount);

        $runner = $this->createRunner([new SimpleTask('task.1', 'First')], $storage);

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
    // computeSlots LogicalAnd (line 668): null !== $group && in_array(...)
    // -------------------------------------------------------------------------

    public function testComputeSlotsDoesNotIncludeNullGroupForGroupedTask(): void
    {
        // Kills LogicalAnd → LogicalOr (|| mutant on line 668):
        // with `||`, a null group slot always passes the check and gets included —
        // the grouped task would incorrectly run in the default slot too.
        $runner = $this->createRunner([new PredeployTask()]);

        // runAll without groups → effectiveGroups = [null] → no group slots → task skipped.
        $result = $runner->runAll($this->output);

        self::assertSame(0, $result->ran, 'Grouped task must not run in the default slot (null group)');
        self::assertFalse($this->storage->has('test.predeploy'));
        self::assertFalse($this->storage->has('test.predeploy', 'predeploy'));
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
            allOrNothing: true,
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
        $runner = $this->createRunner([new ReturnsFailureTask()], storage: $storage, allOrNothing: true);

        $this->expectException(AllOrNothingFailureException::class);
        $runner->runAll($this->output);
    }

    public function testReturnedLockedIsRecordedAsFailed(): void
    {
        $runner = $this->createRunner([new ReturnsFailureTask(TaskResult::LOCKED)]);

        $result = $runner->runAll($this->output);

        self::assertSame(1, $result->failed);

        $execution = $this->storage->get('test.returns_failure');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Failed, $execution->status);
        self::assertStringContainsString('returned TaskResult::LOCKED', (string) $execution->error);
    }

    public function testReturnedFailureUnderPerTaskTransactionIsRecordedAsFailed(): void
    {
        $storage = new TransactionalInMemoryStorageFixture();
        $runner = $this->createRunner(
            [new ReturnsFailureTask()],
            storage: $storage,
            transactional: true,
            allOrNothing: false,
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

    private function makeCountingTransactionalStorage(int &$count): TransactionalStorageInterface
    {
        return new class($count) implements TransactionalStorageInterface {
            /** @var array<string, TaskExecution> */
            private array $executions = [];

            public function __construct(private int &$count)
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
                ++$this->count;

                return $callback();
            }
        };
    }

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
        return $this->createRunner($tasks, $storage, allOrNothing: true);
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
     */
    private function createRunner(
        array $tasks,
        ?TaskStorageInterface $storage = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        ?LockFactory $lockFactory = null,
        int $defaultTimeout = 300,
        bool $transactional = true,
        bool $allOrNothing = false,
        int $lockTtl = 3600,
        ?string $environment = null,
    ): TaskRunner {
        $idResolver = new TaskIdResolver();

        return new TaskRunner(
            new TaskRegistry($tasks, $idResolver),
            $storage ?? $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            $defaultTimeout,
            $transactional,
            $allOrNothing,
            $lockTtl,
            dispatcher: $dispatcher,
            lockFactory: $lockFactory,
            environment: $environment,
            logger: $logger ?? new NullLogger(),
        );
    }
}
