<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskResult;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Contract\TransactionalStorageInterface;
use Soviann\DeployTasks\DefaultTaskIdResolver;
use Soviann\DeployTasks\DefaultTaskOrderResolver;
use Soviann\DeployTasks\Event\AfterTaskEvent;
use Soviann\DeployTasks\Event\BeforeTaskEvent;
use Soviann\DeployTasks\Event\TaskFailedEvent;
use Soviann\DeployTasks\Storage\InMemoryStorage;
use Soviann\DeployTasks\TaskRegistry;
use Soviann\DeployTasks\TaskRunner;
use Soviann\DeployTasks\Tests\Fixtures\FailingTask;
use Soviann\DeployTasks\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasks\Tests\Fixtures\SkippingTask;
use Soviann\DeployTasks\Tests\Fixtures\TransactionalTask;
use Symfony\Component\Console\Output\BufferedOutput;
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
        self::assertStringContainsString('[pending]', $this->output->fetch());
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
            ->willReturnCallback(function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $runner = $this->createRunner(
            [new SimpleTask('task.1', 'First')],
            dispatcher: $dispatcher,
        );

        $runner->runAll($this->output);

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(BeforeTaskEvent::class, $dispatched[0]);
        self::assertInstanceOf(AfterTaskEvent::class, $dispatched[1]);
        self::assertSame('task.1', $dispatched[0]->taskId);
        self::assertSame(TaskResult::SUCCESS, $dispatched[1]->result);
    }

    public function testEventsNotDispatchedWhenNoDispatcher(): void
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
            ->willReturnCallback(function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $runner = $this->createRunner(
            [new FailingTask()],
            dispatcher: $dispatcher,
        );

        $runner->runAll($this->output);

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(BeforeTaskEvent::class, $dispatched[0]);
        self::assertInstanceOf(TaskFailedEvent::class, $dispatched[1]);
        self::assertSame('Task failed!', $dispatched[1]->exception->getMessage());
    }

    public function testNoLockFactoryWarning(): void
    {
        $runner = $this->createRunner([
            new SimpleTask('task.1', 'First'),
        ]);

        $runner->runAll($this->output);

        self::assertStringContainsString('No lock factory configured', $this->output->fetch());
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
        $storage = $this->createMock(TransactionalStorageInterface::class);
        $storage->method('has')->willReturn(false);
        $storage->method('get')->willReturn(null);
        $storage->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback());
        $storage->expects(self::once())->method('save');

        $idResolver = new DefaultTaskIdResolver();

        $runner = new TaskRunner(
            new TaskRegistry([new TransactionalTask()], $idResolver),
            $storage,
            new DefaultTaskOrderResolver($idResolver),
            $idResolver,
        );

        $runner->runAll($this->output);
    }

    public function testRunOneFailingTask(): void
    {
        $runner = $this->createRunner([new FailingTask()]);

        $result = $runner->runOne('test.failing', $this->output);

        self::assertSame(TaskResult::FAILURE, $result);
        self::assertSame(TaskStatus::Failed, $this->storage->get('test.failing')?->status);
    }

    /**
     * @param array<\Soviann\DeployTasks\Contract\DeployTaskInterface> $tasks
     */
    private function createRunner(
        array $tasks,
        ?TaskStorageInterface $storage = null,
        ?EventDispatcherInterface $dispatcher = null,
    ): TaskRunner {
        $idResolver = new DefaultTaskIdResolver();

        return new TaskRunner(
            new TaskRegistry($tasks, $idResolver),
            $storage ?? $this->storage,
            new DefaultTaskOrderResolver($idResolver),
            $idResolver,
            $dispatcher,
        );
    }
}
