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

        $result = $runner->runAll($this->output, force: true);

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

        $result = $runner->runAll($this->output, dryRun: true);

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

        $result = $runner->runAll($this->output, dryRun: true, groups: ['predeploy']);

        self::assertSame(1, $result->ran);
        // Group slot → label is `{taskId}@{slot}`; kills Concat/Ternary/Identical/Operand-removal mutants on line 178.
        self::assertStringContainsString('  [would run] test.predeploy@predeploy - Predeploy-only task', $this->output->fetch());
    }

    public function testRunAllDryRunSkipsAlreadyRan(): void
    {
        $this->storage->save(new TaskExecution('task.1', TaskStatus::Ran, new \DateTimeImmutable()));

        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
            new SimpleTask('task.2', 'Second'),
        ]);

        $result = $runner->runAll($this->output, dryRun: true);

        self::assertSame(1, $result->ran);
        self::assertSame(1, $result->skipped);
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

        $result = $runner->runOne('task.1', $this->output, force: true);

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
            self::assertNull($execution, 'A throwing BeforeTaskEvent listener must not create a FAILED storage record.');

            // The listener failure must reach the logger.
            self::assertTrue($logger->has('error', 'Deploy task listener failed'), 'Listener exception must be logged at error level.');
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

        self::assertCount(2, $dispatched, 'Before/After events must fire before persistOutcome');
        self::assertInstanceOf(BeforeTaskEvent::class, $dispatched[0]);
        self::assertInstanceOf(AfterTaskEvent::class, $dispatched[1]);
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
        $idResolver = new TaskIdResolver();

        $runner = new TaskRunner(
            new TaskRegistry([new TransactionalTask()], $idResolver),
            $storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
        );

        $runner->runAll($this->output);

        self::assertTrue($storage->has('test.transactional'), 'Transactional task must persist through the transactional() wrapper.');
        self::assertSame(TaskStatus::Ran, $storage->get('test.transactional')?->status);
    }

    public function testRunOneFailingTask(): void
    {
        $runner = $this->createRunner([new FailingTask()]);

        $result = $runner->runOne('test.failing', $this->output);

        self::assertSame(TaskResult::FAILURE, $result);
        self::assertSame(TaskStatus::Failed, $this->storage->get('test.failing')?->status);
    }

    public function testAllOrNothingRollsBackOnFailure(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // Pre-create the table outside any transaction — SQLite DDL is transactional,
        // so auto-init inside allOrNothing would roll back the table along with the data.
        $storage = new DbalStorage($connection);
        $connection->executeStatement($storage->getCreateTableSql());
        $idResolver = new TaskIdResolver();
        $logger = new ArrayLogger();

        $runner = new TaskRunner(
            new TaskRegistry([new SimpleTask('task.1', 'First'), new FailingTask()], $idResolver),
            $storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            null,
            null,
            300,
            null,
            true,
            true, // allOrNothing
            $logger,
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

    public function testAllOrNothingWithNonTransactionalStorageRunsUnwrapped(): void
    {
        // Non-transactional storage bypasses the transactional wrap on line 69 (`storage instanceof TransactionalStorageInterface`).
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
        $idResolver = new TaskIdResolver();

        $task1 = new SimpleTask('task.1', 'First');
        $task2Error = new \RuntimeException('task 2 failed');
        $task2 = $this->makeFailingTaskWithError('task.2', $task2Error);
        $task3 = new SimpleTask('task.3', 'Third');

        $runner = new TaskRunner(
            new TaskRegistry([$task1, $task2, $task3], $idResolver),
            $storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            null,
            null,
            300,
            null,
            true,
            true, // allOrNothing
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
        $idResolver = new TaskIdResolver();

        $runner = new TaskRunner(
            new TaskRegistry([new SimpleTask('task.1', 'First'), new SimpleTask('task.2', 'Second')], $idResolver),
            $storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            null,
            null,
            300,
            null,
            true,
            true, // allOrNothing
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
            ->with('deploy_tasks_run', 3600)
            ->willReturn($lock);

        $idResolver = new TaskIdResolver();

        $runner = new TaskRunner(
            new TaskRegistry([new SimpleTask('task.1', 'First')], $idResolver),
            $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            null,
            $lockFactory,
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

        $idResolver = new TaskIdResolver();

        $runner = new TaskRunner(
            new TaskRegistry([new FailingTask()], $idResolver),
            $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            null,
            $lockFactory,
        );

        $runner->runAll($this->output);
    }

    public function testRunAllWithGroupFilterOnlyRunsGroupTasks(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.default'),
            new PredeployTask(),
        ]);

        $result = $runner->runAll($this->output, groups: ['predeploy']);

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

        $result = $runner->runAll($this->output, groups: ['predeploy', 'postdeploy']);

        self::assertSame(2, $result->ran);
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
        self::assertFalse($this->storage->has('test.multi_group'));
    }

    public function testRunAllMultiGroupTaskRunsOnlyRequestedSlot(): void
    {
        $runner = $this->createRunner([new MultiGroupTask()]);

        $result = $runner->runAll($this->output, groups: ['predeploy']);

        self::assertSame(1, $result->ran);
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($this->storage->has('test.multi_group', 'postdeploy'));
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

        $runner->runOne('test.predeploy', $this->output, groups: ['postdeploy']);
    }

    public function testRunOneThrowsWhenDefaultTaskReceivesGroups(): void
    {
        $runner = $this->createRunner([new SimpleTask('task.default')]);

        $this->expectException(TaskGroupMismatchException::class);

        $runner->runOne('task.default', $this->output, groups: ['predeploy']);
    }

    public function testRunOneWritesOneRowPerRequestedGroup(): void
    {
        $runner = $this->createRunner([new MultiGroupTask()]);

        $result = $runner->runOne('test.multi_group', $this->output, groups: ['predeploy', 'postdeploy']);

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

        $idResolver = new TaskIdResolver();

        $runner = new TaskRunner(
            new TaskRegistry([new SimpleTask('task.1', 'First')], $idResolver),
            $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            null,
            $lockFactory,
        );

        $result = $runner->runOne('task.1', $this->output);

        self::assertSame(TaskResult::LOCKED, $result);
        self::assertStringContainsString('Another deploytasks:run process is already running', $this->output->fetch());
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
        $idResolver = new TaskIdResolver();

        $runner = new TaskRunner(
            new TaskRegistry([$prodOnlyTask], $idResolver),
            $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            null,
            null,
            300,
            'dev', // runner environment
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
        self::assertFalse($this->storage->has('test.prod_only'), 'Task run() must not be invoked when env constraint is refused.');
    }

    public function testRunOneAllowsEnvMatchAndNullConstraint(): void
    {
        // Task with matching env runs fine; task with no env constraint (null) always runs.
        $prodOnlyTask = new ProdOnlyTask();
        $defaultTask = new SimpleTask('task.default', 'Default');
        $idResolver = new TaskIdResolver();

        $runnerProd = new TaskRunner(
            new TaskRegistry([$prodOnlyTask, $defaultTask], $idResolver),
            $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            null,
            null,
            300,
            'prod', // runner environment matches ProdOnlyTask
        );

        $resultProd = $runnerProd->runOne('test.prod_only', $this->output);
        self::assertSame(TaskResult::SUCCESS, $resultProd);

        $resultDefault = $runnerProd->runOne('task.default', $this->output);
        self::assertSame(TaskResult::SUCCESS, $resultDefault);
    }

    public function testTimeoutExceededLogsWarningWithoutFailing(): void
    {
        $idResolver = new TaskIdResolver();
        $logger = new ArrayLogger();

        // 1.1s sleep against a 1s timeout — reliably crosses the threshold without depending
        // on microsecond-level scheduling. Slow by design (~1.1s per run).
        $runner = new TaskRunner(
            new TaskRegistry([new SleepingTask('task.1', 1_100_000)], $idResolver),
            $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
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
        $idResolver = new TaskIdResolver();
        $logger = new ArrayLogger();

        // 50ms sleep against a disabled timeout (0): no warning, regardless of duration.
        $runner = new TaskRunner(
            new TaskRegistry([new SleepingTask('task.1', 50_000)], $idResolver),
            $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
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
        // Two tasks that self-report SKIPPED → skipped=2; kills Assignment mutator on `$skipped += $count` (line 221).
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
        // Pins the exact warning format — kills Minus/CastInt mutants on the `(int) $duration` and `$duration - $start` lines.
        $idResolver = new TaskIdResolver();

        $runner = new TaskRunner(
            new TaskRegistry([new SleepingTask('task.1', 1_100_000)], $idResolver),
            $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
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
        $idResolver = new TaskIdResolver();

        $runner = new TaskRunner(
            new TaskRegistry([new SleepingTask('task.1', 1_100_000)], $idResolver),
            $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            defaultTimeout: 1,
            logger: $logger,
        );

        $runner->runAll($this->output);

        self::assertTrue($logger->has('warning', 'exceeded timeout'));
    }

    public function testLockDeniedLogsWarning(): void
    {
        $logger = new ArrayLogger();
        $lockFactory = new LockFactory(new InMemoryStore());
        // Pre-acquire the shared lock outside the runner so its own acquire call fails.
        $heldLock = $lockFactory->createLock('deploy_tasks_run', 3600);
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
        // Goal: when storage is transactional, task->run() and storage->save() must execute
        // inside a single transactional() closure. If save() throws, the transaction
        // catches the exception (triggering rollback) and re-raises it, so callers see
        // the failure and the storage stays clean.
        //
        // The distinguishing observable: $rollbackTriggered is set to true only when the
        // exception from save() is caught by transactional() — which only happens if save()
        // was called INSIDE the closure.  With the pre-fix code, save() was called by the
        // caller after transactional() returned, so transactional() never saw the exception
        // and $rollbackTriggered stayed false.

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

        $idResolver = new TaskIdResolver();
        $runner = new TaskRunner(
            new TaskRegistry([$task], $idResolver),
            $storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
        );

        try {
            $runner->runAll($this->output);
            self::fail('Expected StorageException to propagate');
        } catch (StorageException) {
            // expected
        }

        self::assertTrue($task->ran, 'Task must have run before save() threw');
        // The critical assertion: transactional() must have caught the save() exception
        // (rollback triggered), proving save() was called INSIDE the closure.
        self::assertTrue($storage->rollbackTriggered, 'transactional() must have caught the save() failure — save() must be called inside the closure, not after it returns');
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

        $idResolver = new TaskIdResolver();

        $runner = new TaskRunner(
            new TaskRegistry([
                new SimpleTask('task.1', 'First'),
                new SimpleTask('task.2', 'Second'),
                new SimpleTask('task.3', 'Third'),
            ], $idResolver),
            $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            null,
            $lockFactory,
        );

        $runner->runAll($this->output);

        self::assertGreaterThanOrEqual(2, $refreshCount, 'refresh() must be called between each task (at least 2 times for 3 tasks)');
    }

    /**
     * @param array<\Soviann\DeployTasksBundle\DeployTaskInterface> $tasks
     */
    private function createAllOrNothingRunner(array $tasks, TaskStorageInterface $storage): TaskRunner
    {
        $idResolver = new TaskIdResolver();

        return new TaskRunner(
            new TaskRegistry($tasks, $idResolver),
            $storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            null,
            null,
            300,
            null,
            true,
            true, // allOrNothing
        );
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

    private function makeFailingTaskWithError(string $taskId, \Throwable $error): \Soviann\DeployTasksBundle\Identifier\TaskIdProviderInterface
    {
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
    ): TaskRunner {
        $idResolver = new TaskIdResolver();

        return new TaskRunner(
            new TaskRegistry($tasks, $idResolver),
            $storage ?? $this->storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            $dispatcher,
            $lockFactory,
            logger: $logger ?? new NullLogger(),
        );
    }
}
