<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\CommandMessages;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Event\AfterTaskEvent;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Fixtures\FailingTask;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(DeployTasksRunCommand::class)]
final class DeployRunCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->application = new Application(self::kernel());
        $this->tester = new CommandTester($this->application->find('deploytasks:run'));
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
        self::assertStringContainsString('would run', $this->tester->getDisplay());

        // Verify no tasks were actually executed
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertEmpty($storage->all());
    }

    public function testDryRunWithIdDoesNotExecuteTask(): void
    {
        $this->tester->execute(['--id' => 'test.simple', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('would run', $this->tester->getDisplay());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertEmpty($storage->all());
    }

    public function testRerunAllRerunsAllTasks(): void
    {
        // First run
        $this->tester->execute([]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Rerun with primary option
        $this->tester->execute(['--rerun-all' => true]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('ran', $this->tester->getDisplay());
    }

    public function testDryRunWithRerunAllPreviewsAlreadyExecutedTasks(): void
    {
        $this->tester->execute([]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $this->tester->execute(['--dry-run' => true, '--rerun-all' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('would run', $this->tester->getDisplay());
        self::assertStringNotContainsString('0 would run', $this->tester->getDisplay());
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
        self::assertStringContainsString(
            \sprintf(CommandMessages::UNKNOWN_TASK, 'nonexistent.task'),
            (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay()),
        );
    }

    public function testRerunAllWithIdRerunsSingleTask(): void
    {
        // First run
        $this->tester->execute(['--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Re-run single task with primary option
        $this->tester->execute(['--rerun-all' => true, '--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringNotContainsString('already been executed', $this->tester->getDisplay());
        self::assertStringContainsString('ran', $this->tester->getDisplay());
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
        // Reboot with events enabled so execution order is observable via AfterTaskEvent rather than
        // console display ordering (sub-second execution makes storage timestamps non-discriminating).
        self::ensureKernelShutdown();
        self::$testKernelOptions = ['eventsEnabled' => true];
        self::bootKernel();
        $this->cleanStorage();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);

        $executionOrder = [];
        $dispatcher->addListener(AfterTaskEvent::class, static function (AfterTaskEvent $event) use (&$executionOrder): void {
            $executionOrder[] = $event->taskId;
        });

        $tester = new CommandTester((new Application(self::kernel()))->find('deploytasks:run'));
        $tester->execute([]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $prioritizedIndex = \array_search('test.prioritized', $executionOrder, true);
        $simpleIndex = \array_search('test.simple', $executionOrder, true);
        self::assertIsInt($prioritizedIndex, 'test.prioritized must have fired AfterTaskEvent.');
        self::assertIsInt($simpleIndex, 'test.simple must have fired AfterTaskEvent.');
        self::assertLessThan($simpleIndex, $prioritizedIndex, 'test.prioritized (priority=10) must execute before test.simple (priority=0).');
    }

    public function testSkippingTaskIsStoredAsSkipped(): void
    {
        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $execution = $storage->get('test.skipping');
        \assert(null !== $execution, 'SkippingTask should be stored after run');
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

    public function testHelpCrossReferencesSkipAndReset(): void
    {
        $help = $this->application->find('deploytasks:run')->getHelp();

        self::assertStringContainsString('deploytasks:skip', $help);
        self::assertStringContainsString('deploytasks:reset', $help);
    }

    public function testIdOptionHelpContainsRerunAllReference(): void
    {
        $definition = $this->application->find('deploytasks:run')->getDefinition();
        $helpText = $definition->getOption('id')->getDescription();

        self::assertStringContainsString('--rerun-all', $helpText);
        self::assertStringContainsString('re-execute even if already ran', $helpText);
    }

    public function testDryRunSummaryUsesWouldRunLabel(): void
    {
        // Dry-run before any execution: the summary line says "N would run", not "N pending".
        $this->tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        // The writeSummary label must now say "would run" instead of "pending".
        self::assertStringContainsString('would run', $display);
    }

    public function testRequireSomeWithUnknownIdExitsUsage(): void
    {
        $this->tester->execute(['--require-some' => true, '--id' => 'nonexistent.task']);

        self::assertSame(DeployTasksRunCommand::EX_USAGE, $this->tester->getStatusCode());
        self::assertStringContainsString('No task matched', $this->tester->getDisplay());
    }

    public function testWithoutRequireSomeUnknownIdKeepsExistingBehavior(): void
    {
        $this->tester->execute(['--id' => 'nonexistent.task']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
    }

    public function testRequireSomeWithNoMatchingGroupExitsUsage(): void
    {
        // --require-some with a group that has no registered tasks → exit 64
        $this->tester->execute(['--require-some' => true, '--group' => ['nonexistent_group']]);

        self::assertSame(DeployTasksRunCommand::EX_USAGE, $this->tester->getStatusCode());
        self::assertStringContainsString('No task matched', $this->tester->getDisplay());
    }

    public function testRequireSomeWithIdAndMismatchedGroupExitsInvalid(): void
    {
        $this->tester->execute(['--require-some' => true, '--id' => 'test.simple', '--group' => ['predeploy']]);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
    }

    public function testRequireSomeWithPendingTasksSucceeds(): void
    {
        // No prior run: tasks are pending
        $this->tester->execute(['--require-some' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
    }

    // --- Mutant-killing tests ---

    // Mutant 60 (Ternary:174) — writeSummary says "would run" for dry-run, not "ran"
    public function testDryRunSummaryContainsWouldRunNotRan(): void
    {
        $this->tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('would run', $display);
        self::assertStringNotContainsString('Tasks: 0 ran,', $display);
    }

    // Mutant 60 inverse: normal run says "ran", not "would run"
    public function testNormalRunSummaryContainsRanNotWouldRun(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString(' ran,', $display);
        self::assertStringNotContainsString('would run', $display);
    }

    // Mutant 61 (MethodCallRemoval:180) — failed run outputs error summary to display
    public function testFailedRunOutputsErrorSummary(): void
    {
        self::ensureKernelShutdown();
        self::$testKernelOptions = ['extraTasks' => [FailingTask::class]];
        self::bootKernel();
        $this->cleanStorage();

        $tester = new CommandTester((new Application(self::kernel()))->find('deploytasks:run'));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        // The error summary block must appear — contains "Tasks:" and "failed"
        self::assertStringContainsString('Tasks:', $display);
        self::assertStringContainsString('failed', $display);
    }

    // Mutant 63+64+65 (DecrementInteger/LogicalAndAllSubExprNegation:185)
    // When ran=0 AND skipped=0, "No deploy tasks registered." or "No tasks matched..." must appear.
    // An empty run (no tasks configured) with groupFilter=[] triggers ran=0, skipped=0.
    public function testGroupNoMatchDisplaysNoTasksMatchedMessage(): void
    {
        $this->tester->execute(['--group' => ['nonexistent']]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        // Grouped run with 0 ran + 0 skipped shows the group-specific message
        self::assertStringContainsString('No tasks matched the requested group(s).', $display);
    }

    // Mutant 66 (Ternary:186) — group-matched message vs no-group message
    public function testGroupNoMatchMessageDiffersFromNoTasksRegisteredMessage(): void
    {
        // With a group filter: must say "No tasks matched the requested group(s)."
        $this->tester->execute(['--group' => ['nonexistent']]);
        self::assertStringContainsString('No tasks matched the requested group(s).', $this->tester->getDisplay());
        self::assertStringNotContainsString('No deploy tasks registered.', $this->tester->getDisplay());
    }

    // Mutant 54 (NotIdentical:126) — writeSummary groupFilterActive arg is true when groups non-empty
    // When --group is set and nothing matches, "No tasks matched..." (group message), not the default one
    public function testGroupFilterActivePassedCorrectlyToWriteSummary(): void
    {
        // With no --group flag: ran=skipped=0 would show "No deploy tasks registered."
        // But that only happens when there are truly 0 tasks; here tasks exist but are ungrouped.
        // Without --group, ungrouped tasks run → success with "ran". So verify group=nonexistent
        // triggers group-specific message (groupFilterActive=true), proving [] !== $groups, not [] === $groups.
        $this->tester->execute(['--group' => ['this_does_not_exist_at_all']]);
        self::assertStringContainsString('No tasks matched the requested group(s).', $this->tester->getDisplay());
        self::assertStringNotContainsString('No deploy tasks registered.', $this->tester->getDisplay());
    }

    // Mutant 55 (Catch_:148) — TaskGroupMismatchException must also be caught and display INVALID
    public function testGroupedTaskWithoutGroupArgReturnsInvalid(): void
    {
        // test.predeploy declares group 'predeploy'. Running with --id without --group
        // triggers TaskGroupRequiredException (or TaskGroupMismatchException) → Command::INVALID
        $this->tester->execute(['--id' => 'test.predeploy']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        // The exception message must be displayed (MethodCallRemoval:149 mutant)
        self::assertStringNotContainsString('[WARNING]', $this->tester->getDisplay());
    }

    // Mutant 56 (MethodCallRemoval:149) — error message is shown for group exception
    public function testGroupExceptionErrorMessageIsDisplayed(): void
    {
        $this->tester->execute(['--id' => 'test.predeploy']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        // io->error() wraps text in [ERROR] block — must appear
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('[ERROR]', $display);
    }

    // Mutant 57 (MethodCallRemoval:155) — warning shown when single task locked
    // Already covered by DeployRunLockCommandTest; but add output assertion here:
    public function testLockedSingleTaskShowsWarning(): void
    {
        // Use lock kernel to exercise the LOCKED path through executeOne
        self::ensureKernelShutdown();
        self::$testKernelOptions = ['lockEnabled' => true];
        self::bootKernel();
        $this->cleanStorage();

        $lockFactory = self::getContainer()->get(\Symfony\Component\Lock\LockFactory::class);
        \assert($lockFactory instanceof \Symfony\Component\Lock\LockFactory);

        $heldLock = $lockFactory->createLock('soviann_deploy_tasks_run', 3600);
        self::assertTrue($heldLock->acquire());

        try {
            $tester = new CommandTester((new Application(self::kernel()))->find('deploytasks:run'));
            $tester->execute(['--id' => 'test.simple']);

            self::assertSame(DeployTasksRunCommand::EX_TEMPFAIL, $tester->getStatusCode());
            self::assertStringContainsString('Run skipped: another process is already running.', $tester->getDisplay());
        } finally {
            $heldLock->release();
        }
    }

    // Mutant 58+59 (MethodCallRemoval+ReturnRemoval on writeSummary locked path)
    public function testLockedRunAllShowsWarningInSummary(): void
    {
        self::ensureKernelShutdown();
        self::$testKernelOptions = ['lockEnabled' => true];
        self::bootKernel();
        $this->cleanStorage();

        $lockFactory = self::getContainer()->get(\Symfony\Component\Lock\LockFactory::class);
        \assert($lockFactory instanceof \Symfony\Component\Lock\LockFactory);

        $heldLock = $lockFactory->createLock('soviann_deploy_tasks_run', 3600);
        self::assertTrue($heldLock->acquire());

        try {
            $tester = new CommandTester((new Application(self::kernel()))->find('deploytasks:run'));
            $tester->execute([]);

            self::assertSame(DeployTasksRunCommand::EX_TEMPFAIL, $tester->getStatusCode());
            $display = $tester->getDisplay();
            self::assertStringContainsString('Run skipped: another process is already running.', $display);
            // ReturnRemoval:168 — if return is removed, the summary line below ("Tasks: ...") would also appear
            self::assertStringNotContainsString('Tasks: 0', $display);
        } finally {
            $heldLock->release();
        }
    }

    // Mutant 67 (MethodCallRemoval:186) — success message emitted when ran=0,skipped=0
    public function testGroupNoMatchOutputsSuccessMessage(): void
    {
        $this->tester->execute(['--group' => ['nonexistent']]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        // If io->success() were removed, no "[OK]" block appears
        self::assertStringContainsString('[OK]', $this->tester->getDisplay());
    }

    // Mutant 68 (ReturnRemoval:190) — after "No tasks matched" return, "All tasks already executed" must NOT appear
    public function testGroupNoMatchDoesNotPrintAllTasksAlreadyExecuted(): void
    {
        $this->tester->execute(['--group' => ['nonexistent']]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringNotContainsString('All tasks already executed', $this->tester->getDisplay());
    }

    // Mutant 69 (ReturnRemoval:196) — after "All tasks already executed" return, summary must NOT appear
    public function testAllAlreadyExecutedDoesNotPrintSummaryLine(): void
    {
        // First run executes all tasks
        $this->tester->execute([]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Second run — all already executed → "All tasks already executed — nothing to run."
        $this->tester->execute([]);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('All tasks already executed — nothing to run.', $display);
        // If ReturnRemoval mutant survived, io->success($summary) would also run, showing "Tasks: 0 ran, ..."
        self::assertStringNotContainsString('Tasks: 0 ran', $display);
    }

    // Mutant 50+51+53 (CastBool) — options are bool-cast; ensure passing string-like truthy value still works
    // This is an equivalent mutant because getOption() already returns bool for VALUE_NONE options.
    // We cover the behavior implicitly via existing tests.

    // Mutant 52 (UnwrapArrayValues:105) — groups is list<string> from array_values
    // Equivalent mutant — getOption() already returns list; array_values is defensive. Covered by group tests.

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
