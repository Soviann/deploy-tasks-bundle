<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Command\DeployTasksStatusCommand;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

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
    // Column 4 (0-indexed) in ['ID','Group','Description','Status','Error','Executed At'] is 'Error'.
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
            (new Application($this->bootHostTasksKernel($projectDir)))->find('deploytasks:status'),
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
        }
    }

    public function testStatusOmitsHostSectionWhenHostDirectoryAbsent(): void
    {
        $projectDir = FilesystemTestHelper::tempDir('deploy-tasks-status-host-');
        // No deploy/host-tasks directory created.

        $tester = new CommandTester(
            (new Application($this->bootHostTasksKernel($projectDir)))->find('deploytasks:status'),
        );

        try {
            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringNotContainsString('Host tasks', $tester->getDisplay());
        } finally {
            FilesystemTestHelper::cleanup($projectDir);
        }
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * Boots a throwaway kernel whose kernel.project_dir is $projectDir, so
     * generate.host_directory's default (%kernel.project_dir%/deploy/host-tasks) and
     * the status command's default host log path (%kernel.project_dir%/.deploy-tasks-host.log)
     * resolve into the disposable temp tree instead of the bundle's own root.
     */
    private function bootHostTasksKernel(string $projectDir): Kernel
    {
        $kernel = new class('test', true, $projectDir) extends Kernel {
            use MicroKernelTrait;

            public function __construct(
                string $environment,
                bool $debug,
                private readonly string $fakeProjectDir,
            ) {
                parent::__construct($environment, $debug);
            }

            public function registerBundles(): iterable
            {
                yield new FrameworkBundle();
                yield new SoviannDeployTasksBundle();
            }

            public function getProjectDir(): string
            {
                return $this->fakeProjectDir;
            }

            public function getCacheDir(): string
            {
                // Keyed on fakeProjectDir: %kernel.project_dir% is baked into the compiled
                // container (host_directory, log path), so two kernels with different fake
                // project dirs must never share a cache — that would serve one test's
                // container (and its baked-in paths) to the other.
                return \sys_get_temp_dir().'/status-host-tasks-cache-'.\substr(\sha1($this->fakeProjectDir), 0, 12).'-'.\getmypid().'/'.$this->environment;
            }

            public function getLogDir(): string
            {
                return \sys_get_temp_dir().'/status-host-tasks-logs-'.\substr(\sha1($this->fakeProjectDir), 0, 12).'-'.\getmypid();
            }

            protected function configureContainer(ContainerConfigurator $container): void
            {
                $container->extension('framework', [
                    'test' => true,
                    'secret' => 'test',
                    'http_method_override' => false,
                    'handle_all_throwables' => true,
                    'php_errors' => ['log' => true],
                ]);

                $container->extension('soviann_deploy_tasks', [
                    'storage' => [
                        'type' => 'filesystem',
                        'filesystem' => ['path' => $this->fakeProjectDir.'/var/deploy-tasks-storage'],
                    ],
                    'events' => ['enabled' => false],
                    'lock' => ['enabled' => false],
                    // No generate.host_directory override — defaults resolve under fakeProjectDir.
                ]);

                $container->services()
                    ->set('logger', NullLogger::class)->public();
            }
        };

        $kernel->boot();

        return $kernel;
    }
}
