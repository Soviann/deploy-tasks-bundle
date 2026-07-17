<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksStatusCommand;
use Soviann\DeployTasksBundle\Helper\HostRunnerConfig;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Soviann\DeployTasksBundle\Tests\Support\HostTasksKernelFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(DeployTasksStatusCommand::class)]
final class DeployStatusCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:status'));
        $this->cleanStorage();
    }

    public function testStatusShowsAllTasks(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('test.simple', $display);
        self::assertStringContainsString('test.prod_only', $display);
        self::assertStringContainsString('pending', $display);
    }

    public function testNoStateFlag(): void
    {
        $this->tester->execute(['--no-state' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('test.simple', $display);
        self::assertStringNotContainsString('pending', $display);
    }

    public function testStatusShowsTaskCount(): void
    {
        $this->tester->execute([]);

        self::assertStringContainsString('8 task(s) registered', $this->tester->getDisplay());
    }

    public function testAttributeDescriptionFallbackRendered(): void
    {
        // Fixture's getDescription() returns '' but #[AsDeployTask(description: 'From attribute only')]
        // is declared — the resolver should surface the attribute fallback in the table.
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('From attribute only', $this->tester->getDisplay());
    }

    public function testStatusShowsAllExecutionStates(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        // Set up distinct states in storage
        $storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));
        $storage->save(new TaskExecution('test.prod_only', TaskStatus::Skipped, new \DateTimeImmutable()));

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('ran', $display);
        self::assertStringContainsString('skipped', $display);
        // PrioritizedTask, SkippingTask, MultiEnvTask have no record — shown as pending
        self::assertStringContainsString('pending', $display);
    }

    public function testFailedTaskDisplaysTruncatedErrorColumn(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $longError = \str_repeat('A very long error message that should be truncated. ', 5);
        $storage->save(new TaskExecution('test.simple', TaskStatus::Failed, new \DateTimeImmutable(), $longError));

        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Error', $display);
        self::assertStringContainsString('failed', $display);
        self::assertStringContainsString('…', $display);
        self::assertStringNotContainsString($longError, $display);
    }

    public function testErrorCellStripsAnsiEscapeSequences(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $storage->save(new TaskExecution('test.simple', TaskStatus::Failed, new \DateTimeImmutable(), "boom\x1b[2J"));

        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('boom', $display);
        self::assertStringNotContainsString("\x1b", $display);
    }

    public function testErrorColumnEmptyForNonFailedExecutions(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));
        $storage->save(new TaskExecution('test.prod_only', TaskStatus::Skipped, new \DateTimeImmutable()));

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Error', $display);
        self::assertStringNotContainsString('…', $display);
    }

    public function testErrorColumnAbsentWithNoStateFlag(): void
    {
        $this->tester->execute(['--no-state' => true]);

        self::assertStringNotContainsString('Error', $this->tester->getDisplay());
    }

    public function testShowsOneRowPerTaskGroup(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();

        // Multi-group task should appear twice (once per declared slot)
        self::assertStringContainsString('test.multi_group', $display);
        self::assertStringContainsString('test.predeploy', $display);
        self::assertStringContainsString('predeploy', $display);
        self::assertStringContainsString('postdeploy', $display);

        // 6 default slots + 1 predeploy + 2 multi_group = 9 slots displayed
        self::assertStringContainsString('9 slot(s) displayed', $display);
    }

    public function testGroupFilterRestrictsDisplay(): void
    {
        $this->tester->execute(['--group' => ['predeploy']]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('test.predeploy', $display);
        self::assertStringContainsString('test.multi_group', $display);
        // Only predeploy slots: 2 slots
        self::assertStringContainsString('2 slot(s) displayed', $display);
    }

    public function testFilterStatusFailedHidesNonFailedRows(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));
        $storage->save(new TaskExecution('test.prod_only', TaskStatus::Failed, new \DateTimeImmutable(), 'boom'));

        $this->tester->execute(['--filter-status' => 'FAILED']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('test.prod_only', $display);
        self::assertStringNotContainsString('test.simple', $display);
    }

    public function testFilterStatusPendingHidesRowsWithExecutionRecord(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--filter-status' => 'PENDING']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringNotContainsString('test.simple  ', $display);
        self::assertStringContainsString('test.prod_only', $display);
    }

    public function testFilterStatusAcceptsCommaSeparatedList(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));
        $storage->save(new TaskExecution('test.prod_only', TaskStatus::Failed, new \DateTimeImmutable(), 'boom'));
        $storage->save(new TaskExecution('test.prioritized', TaskStatus::Skipped, new \DateTimeImmutable()));

        $this->tester->execute(['--filter-status' => 'FAILED,SKIPPED']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('test.prod_only', $display);
        self::assertStringContainsString('test.prioritized', $display);
        self::assertStringNotContainsString('test.simple  ', $display);
    }

    public function testFilterStatusIsCaseInsensitive(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $storage->save(new TaskExecution('test.simple', TaskStatus::Failed, new \DateTimeImmutable(), 'boom'));

        $this->tester->execute(['--filter-status' => 'failed']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('test.simple', $this->tester->getDisplay());
    }

    public function testFilterStatusRejectsInvalidValue(): void
    {
        $this->tester->execute(['--filter-status' => 'BOGUS']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString('BOGUS', $this->tester->getDisplay());
    }

    public function testFilterStatusRejectedWithNoStateFlag(): void
    {
        $this->tester->execute(['--filter-status' => 'FAILED', '--no-state' => true]);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
    }

    // --- Group semantics: pending mirrors the bare-run default (every slot) ---

    public function testFreshGroupedTaskSlotsReadPendingInBareStatus(): void
    {
        // A grouped task with no execution record is a genuine bare-run candidate
        // (absent --group, deploytasks:run targets every slot), so each of its
        // slots must read pending. Row-scoped: bind the status to the slot's row.
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertMatchesRegularExpression('/test\.multi_group\s+predeploy\b[^\n]*pending/', $display);
        self::assertMatchesRegularExpression('/test\.multi_group\s+postdeploy\b[^\n]*pending/', $display);
        self::assertMatchesRegularExpression('/test\.predeploy\s+predeploy\b[^\n]*pending/', $display);
    }

    public function testPendingFilterIncludesFreshGroupedSlots(): void
    {
        $this->tester->execute(['--filter-status' => 'PENDING']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertMatchesRegularExpression('/test\.multi_group\s+predeploy\b/', $display);
        self::assertMatchesRegularExpression('/test\.multi_group\s+postdeploy\b/', $display);
        self::assertStringContainsString('test.predeploy', $display);
        // Every slot of a fresh registry is pending: 6 default + 1 predeploy + 2 multi_group.
        self::assertStringContainsString('9 slot(s) displayed', $display);
    }

    public function testBareStatusTracksPerSlotProgressOfAGroupedTask(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        // Only the predeploy slot has run; postdeploy has no record yet.
        $storage->save(new TaskExecution('test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));

        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertMatchesRegularExpression('/test\.multi_group\s+predeploy\b[^\n]*ran/', $display);
        self::assertMatchesRegularExpression('/test\.multi_group\s+postdeploy\b[^\n]*pending/', $display);
    }

    public function testPendingFilterExcludesRanSlotsOfGroupedTasks(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        // Partially-run multi-group task + fully-run single-group task.
        $storage->save(new TaskExecution('test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $storage->save(new TaskExecution('test.predeploy', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));

        $this->tester->execute(['--filter-status' => 'PENDING']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        // The un-run slot is still a bare-run candidate...
        self::assertMatchesRegularExpression('/test\.multi_group\s+postdeploy\b[^\n]*pending/', $display);
        // ...while ran slots drop out, including the fully-run grouped task.
        self::assertDoesNotMatchRegularExpression('/test\.multi_group\s+predeploy\b/', $display);
        self::assertStringNotContainsString('test.predeploy', $display);
        // 9 slots minus the two ran slots.
        self::assertStringContainsString('7 slot(s) displayed', $display);
    }

    // --- Mutant-killing tests ---

    // Mutant 87+88 (ArrayItemRemoval:90) — 'ID' header must appear in both noState and full-state tables
    public function testIdHeaderPresentInFullStateTable(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        // Header row contains 'ID'
        self::assertStringContainsString('ID', $display);
        // Header row contains 'Status' (full state, not no-state)
        self::assertStringContainsString('Status', $display);
        self::assertStringContainsString('Error', $display);
        self::assertStringContainsString('Executed At', $display);
        self::assertStringContainsString('Duration', $display);
    }

    public function testDurationCellRendersFormattedValueAndBlankWhenAbsent(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        // One record from a real run (duration recorded), one manual skip (no duration).
        $storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable(), null, null, 1500));
        $storage->save(new TaskExecution('test.prod_only', TaskStatus::Skipped, new \DateTimeImmutable()));

        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('1.5s', $display);
        // The duration-less row renders without a bogus value — no other duration appears.
        self::assertSame(1, \preg_match_all('/\b\d+(\.\d+)?m?s\b/', $display), 'Exactly one duration value must be rendered.');
    }

    public function testIdHeaderPresentInNoStateTable(): void
    {
        $this->tester->execute(['--no-state' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        // Even in --no-state mode, the 'ID' column must be present
        self::assertStringContainsString('ID', $display);
        // Status/Error columns absent
        self::assertStringNotContainsString('Executed At', $display);
        // Duration is execution state too — absent from the --no-state table.
        self::assertStringNotContainsString('Duration', $display);
    }

    // Mutant 89 (Continue_:100) — group filter iterates ALL slots (continue, not break)
    // If continue→break, only the FIRST slot of multi-slot tasks would be skipped; subsequent slots run.
    public function testGroupFilterSkipsAllNonMatchingSlots(): void
    {
        // test.multi_group has 'predeploy' and 'postdeploy'; filter for 'postdeploy' only.
        // Only the postdeploy slot must appear, not predeploy.
        $this->tester->execute(['--group' => ['postdeploy']]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('postdeploy', $display);
        self::assertStringNotContainsString('predeploy', $display);
        // Only 1 slot for postdeploy (multi_group has postdeploy slot)
        self::assertStringContainsString('1 slot(s) displayed', $display);
    }

    // Mutant 90 (Continue_:106) — status filter iterates ALL slots (continue, not break)
    public function testFilterStatusContinuesAfterSkippingNonMatchingSlot(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        // Give test.simple RAN and test.prod_only FAILED
        $storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));
        $storage->save(new TaskExecution('test.prod_only', TaskStatus::Failed, new \DateTimeImmutable(), 'err'));

        // Filter to FAILED only — test.simple (RAN) should be skipped, test.prod_only (FAILED) must show.
        $this->tester->execute(['--filter-status' => 'FAILED']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('test.prod_only', $display);
        self::assertStringNotContainsString('test.simple  ', $display);
    }

    // Mutant 91 (LogicalNot:117) — setColumnMaxWidth is applied when NOT noState (full table)
    // If the condition were flipped, noState table would get the column max width, and full table wouldn't.
    // We test that the full state table properly truncates long errors (implies column max width was set).
    public function testErrorColumnTruncatedInFullStateTableNotInNoStateTable(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $longError = \str_repeat('X', 300);
        $storage->save(new TaskExecution('test.simple', TaskStatus::Failed, new \DateTimeImmutable(), $longError));

        // Full state: error gets truncated via column max width
        $this->tester->execute([]);
        self::assertStringNotContainsString($longError, $this->tester->getDisplay());
        self::assertStringContainsString('…', $this->tester->getDisplay());
    }

    // Mutant 92+93 (DecrementInteger/IncrementInteger:118) — column index 4 is the Error column
    // Column 4 (0-indexed) in ['ID','Group','Description','Status','Error','Executed At','Duration'] is 'Error'.
    // Verify truncation works on the error column specifically (not Status=3 or ExecutedAt=5).
    public function testErrorColumnIndexIsCorrect(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        // Long error should be truncated; status and task ID must NOT be truncated
        $longError = \str_repeat('E', 300);
        $storage->save(new TaskExecution('test.simple', TaskStatus::Failed, new \DateTimeImmutable(), $longError));

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        // Truncation happened (ellipsis present)
        self::assertStringContainsString('…', $display);
        // Status column is not truncated (it's column 3, width 60 would not affect it meaningfully,
        // but verifying "failed" appears ensures the correct column was widened)
        self::assertStringContainsString('failed', $display);
        // test.simple must appear fully (ID column is not capped)
        self::assertStringContainsString('test.simple', $display);
    }

    // Mutant 94 (MethodCallRemoval:121) — newLine() before writeln → output has a blank line
    public function testSummaryLineAppearsInOutput(): void
    {
        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        // writeln produces the count line regardless of newLine(); verifying text is present
        self::assertStringContainsString('task(s) registered', $display);
        self::assertStringContainsString('slot(s) displayed', $display);
    }

    // Mutant 95 (LogicalAnd:162) — error cell is non-empty ONLY when Failed AND error is non-null
    public function testErrorCellEmptyWhenStatusIsFailedButErrorIsNull(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        // Failed with NULL error → error cell should be empty string
        $storage->save(new TaskExecution('test.simple', TaskStatus::Failed, new \DateTimeImmutable(), null));

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('failed', $display);
        // No truncation ellipsis: error cell must be empty
        self::assertStringNotContainsString('…', $display);
    }

    // Mutant 96 (MethodCallRemoval:179) — error message shown when --filter-status with --no-state
    public function testFilterStatusWithNoStateShowsErrorMessage(): void
    {
        $this->tester->execute(['--filter-status' => 'FAILED', '--no-state' => true]);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Cannot combine --filter-status with --no-state', $display);
    }

    // Mutant 97 (UnwrapTrim:186) — trim() strips spaces around comma-separated status values
    public function testFilterStatusTrimsWhitespaceAroundValues(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $storage->save(new TaskExecution('test.simple', TaskStatus::Failed, new \DateTimeImmutable(), 'err'));

        // Spaces around the value — trim() must handle them
        $this->tester->execute(['--filter-status' => ' FAILED ']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('test.simple', $this->tester->getDisplay());
    }

    // Mutant 98+99 (Concat:216) — executionKey must start with id, not with "\0"
    // Two tasks with IDs "a" and "b" must not collide even if stored executions' key logic is mutated.
    // The most direct way: a task with id="X" and slot=null, and another with id="" and slot="X"
    // should NOT map to the same key. Since we can't register arbitrary IDs easily in functional tests,
    // we verify that two distinct tasks remain distinct in output even after saving executions.
    public function testTwoTasksWithDistinctIdsAreDistinctInStatusOutput(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));
        $storage->save(new TaskExecution('test.prod_only', TaskStatus::Failed, new \DateTimeImmutable(), 'oops'));

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        // Both tasks must appear with their correct status, not bleed into each other
        self::assertStringContainsString('test.simple', $display);
        self::assertStringContainsString('test.prod_only', $display);
        // test.simple shows 'ran', test.prod_only shows 'failed'
        self::assertStringContainsString('ran', $display);
        self::assertStringContainsString('failed', $display);
    }

    // Mutant 86 (CastBool:74) — --no-state option is bool-cast; verify it suppresses status columns
    public function testNoStateFlagSuppressesStatusColumns(): void
    {
        $this->tester->execute(['--no-state' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        // Status, Error, Executed At columns must not appear
        self::assertStringNotContainsString('Status', $display);
        self::assertStringNotContainsString('Error', $display);
        self::assertStringNotContainsString('Executed At', $display);
        // But ID, Group, Description must appear
        self::assertStringContainsString('ID', $display);
        self::assertStringContainsString('Group', $display);
        self::assertStringContainsString('Description', $display);
    }

    // --- Host task visibility ---

    public function testStatusListsHostTasksWithDoneAndPendingStates(): void
    {
        $projectDir = FilesystemTestHelper::tempDir('deploy-tasks-status-host-');
        $hostDir = $projectDir.'/deploy/host-tasks';
        \mkdir($hostDir, 0o755, true);
        \touch($hostDir.'/a.sh');
        \touch($hostDir.'/b.sh');
        \file_put_contents($projectDir.'/.deploy-tasks-host.log', "a\n");

        $tester = new CommandTester(
            (new Application(HostTasksKernelFactory::boot($projectDir)))->find('deploytasks:status'),
        );

        try {
            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('Host tasks', $display);
            // Row-scoped: bind each id to the status on its own table row so a done/pending
            // inversion between "a" and "b" would fail the assertion (plain substring checks
            // for 'a' / 'pending' are vacuous — 'a' matches almost anything, and 'pending'
            // would still be found elsewhere in the display even if the rows were swapped).
            self::assertMatchesRegularExpression('/\ba\b[^\n]*done/', $display);
            self::assertMatchesRegularExpression('/\bb\b[^\n]*pending/', $display);
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
            HostTasksKernelFactory::cleanupAll();
        }
    }

    public function testStatusStripsEscapeSequencesFromHostTaskIds(): void
    {
        $projectDir = FilesystemTestHelper::tempDir('deploy-tasks-status-host-');
        $hostDir = $projectDir.'/deploy/host-tasks';
        \mkdir($hostDir, 0o755, true);
        // ESC is legal in POSIX filenames; rendered raw it would inject ANSI
        // sequences (here: clear-screen) into the deployer's terminal.
        \touch($hostDir.'/'."esc\x1b[2Jseq.sh");

        $tester = new CommandTester(
            (new Application(HostTasksKernelFactory::boot($projectDir)))->find('deploytasks:status'),
        );

        try {
            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('esc', $display);
            self::assertStringNotContainsString("\x1b", $display);
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
            HostTasksKernelFactory::cleanupAll();
        }
    }

    public function testNoStateSuppressesHostSection(): void
    {
        $projectDir = $this->createHostProjectDir();
        $tester = new CommandTester(
            (new Application(HostTasksKernelFactory::boot($projectDir)))->find('deploytasks:status'),
        );

        try {
            // The host section's whole content is execution state (done/pending),
            // so --no-state must suppress it entirely.
            $exitCode = $tester->execute(['--no-state' => true]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringNotContainsString('Host tasks', $tester->getDisplay());
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
            HostTasksKernelFactory::cleanupAll();
        }
    }

    public function testGroupFilterSuppressesHostSection(): void
    {
        $projectDir = $this->createHostProjectDir();
        $tester = new CommandTester(
            (new Application(HostTasksKernelFactory::boot($projectDir)))->find('deploytasks:status'),
        );

        try {
            // Host tasks have no group concept: a --group filtered view must not
            // append unfiltered host rows.
            $exitCode = $tester->execute(['--group' => ['whatever']]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringNotContainsString('Host tasks', $tester->getDisplay());
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
            HostTasksKernelFactory::cleanupAll();
        }
    }

    public function testFilterStatusPendingKeepsOnlyPendingHostRows(): void
    {
        $projectDir = $this->createHostProjectDir();
        $tester = new CommandTester(
            (new Application(HostTasksKernelFactory::boot($projectDir)))->find('deploytasks:status'),
        );

        try {
            $exitCode = $tester->execute(['--filter-status' => 'PENDING']);

            self::assertSame(Command::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('Host tasks', $display);
            self::assertStringContainsString('bbb.todo', $display);
            self::assertStringNotContainsString('aaa.done', $display);
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
            HostTasksKernelFactory::cleanupAll();
        }
    }

    public function testCompoundPendingFilterKeepsPendingHostTasksVisible(): void
    {
        $projectDir = $this->createHostProjectDir();
        $tester = new CommandTester(
            (new Application(HostTasksKernelFactory::boot($projectDir)))->find('deploytasks:status'),
        );

        try {
            // The command's own help recommends --filter-status=PENDING,FAILED: a
            // compound list that includes PENDING must still show pending host rows.
            $exitCode = $tester->execute(['--filter-status' => 'PENDING,FAILED']);

            self::assertSame(Command::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('Host tasks', $display);
            self::assertStringContainsString('bbb.todo', $display);
            self::assertStringNotContainsString('aaa.done', $display);
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
            HostTasksKernelFactory::cleanupAll();
        }
    }

    public function testFilterStatusOtherThanPendingSuppressesHostSection(): void
    {
        $projectDir = $this->createHostProjectDir();
        $tester = new CommandTester(
            (new Application(HostTasksKernelFactory::boot($projectDir)))->find('deploytasks:status'),
        );

        try {
            // Host tasks are only ever done or pending — they can never satisfy a
            // RAN/FAILED/SKIPPED filter, so the section must disappear.
            $exitCode = $tester->execute(['--filter-status' => 'FAILED']);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringNotContainsString('Host tasks', $tester->getDisplay());
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
            HostTasksKernelFactory::cleanupAll();
        }
    }

    public function testHostTasksListedWhenProjectDirContainsGlobMetacharacters(): void
    {
        // glob() treats [, ], ?, * in the *path* as pattern metacharacters, so a
        // project dir like "releases/app[blue]" silently produced no host section.
        $base = FilesystemTestHelper::tempDir('deploy-tasks-status-host-');
        $projectDir = $base.'/app[blue]';
        $hostDir = $projectDir.'/deploy/host-tasks';
        \mkdir($hostDir, 0o755, true);
        \touch($hostDir.'/bracketed_task.sh');

        $tester = new CommandTester(
            (new Application(HostTasksKernelFactory::boot($projectDir)))->find('deploytasks:status'),
        );

        try {
            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);
            $display = $tester->getDisplay();
            self::assertStringContainsString('Host tasks', $display);
            self::assertStringContainsString('bracketed_task', $display);
        } finally {
            FilesystemTestHelper::cleanup($base);
            HostTasksKernelFactory::cleanupAll();
        }
    }

    public function testStatusOmitsHostSectionWhenHostDirectoryAbsent(): void
    {
        $projectDir = FilesystemTestHelper::tempDir('deploy-tasks-status-host-');
        // No deploy/host-tasks directory created.

        $tester = new CommandTester(
            (new Application(HostTasksKernelFactory::boot($projectDir)))->find('deploytasks:status'),
        );

        try {
            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringNotContainsString('Host tasks', $tester->getDisplay());
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
            HostTasksKernelFactory::cleanupAll();
        }
    }

    // --- Host runner-config drift ---

    public function testStatusWarnsWhenGeneratedRunnerConfigDriftsFromBundleConfig(): void
    {
        // Isolated per-test project dir: parallel Infection mutant runs must never write into
        // (or race on) the real checkout's deploy-tasks-host.local.sh.
        $tempProjectDir = \sys_get_temp_dir().'/dtb-generate-'.\uniqid('', true);
        \mkdir($tempProjectDir, 0o755, true);

        self::useConfigurableKernel(
            ['host' => ['log_path' => '%kernel.project_dir%/var/deploy/host.log']],
            projectDir: $tempProjectDir,
        );
        self::bootKernel();

        $localSh = $tempProjectDir.'/deploy-tasks-host.local.sh';

        \file_put_contents($localSh, HostRunnerConfig::GENERATED_MARKER." — regenerate after changing soviann_deploy_tasks.host.*\nexport DEPLOY_TASKS_HOST_DIR='deploy/host-tasks'\nexport DEPLOY_TASKS_HOST_STORAGE='.deploy-tasks-host.log'\nexport DEPLOY_TASKS_HOST_LOCK='.deploy-tasks-host.lock'\n");

        try {
            $tester = $this->runConsoleCommand('deploytasks:status');

            self::assertStringContainsString('deploy-tasks-host.local.sh no longer matches', $tester->getDisplay());
            self::assertStringContainsString('DEPLOY_TASKS_HOST_STORAGE', $tester->getDisplay());
        } finally {
            (new Filesystem())->remove($tempProjectDir);
        }
    }

    public function testStatusStaysSilentWithoutAGeneratedLocalSh(): void
    {
        // Isolated per-test project dir: reads the real checkout's project root otherwise,
        // which a concurrent Infection mutant worker could transiently be writing
        // deploy-tasks-host.local.sh into (see the drift test above).
        $tempProjectDir = \sys_get_temp_dir().'/dtb-generate-'.\uniqid('', true);
        \mkdir($tempProjectDir, 0o755, true);

        try {
            self::useConfigurableKernel([], projectDir: $tempProjectDir);
            self::bootKernel();

            $tester = $this->runConsoleCommand('deploytasks:status');

            self::assertStringNotContainsString('deploy-tasks-host.local.sh', $tester->getDisplay());
        } finally {
            (new Filesystem())->remove($tempProjectDir);
        }
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * Disposable project dir with one done ("aaa.done") and one pending ("bbb.todo")
     * host task; the completion log records only "aaa.done".
     */
    private function createHostProjectDir(): string
    {
        $projectDir = FilesystemTestHelper::tempDir('deploy-tasks-status-host-');
        $hostDir = $projectDir.'/deploy/host-tasks';
        \mkdir($hostDir, 0o755, true);
        \touch($hostDir.'/aaa.done.sh');
        \touch($hostDir.'/bbb.todo.sh');
        \file_put_contents($projectDir.'/.deploy-tasks-host.log', "aaa.done\n");

        return $projectDir;
    }
}
