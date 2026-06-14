<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksShowCommand;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksShowCommand::class)]
final class DeployShowCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private TaskStorageInterface $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:show'));

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $this->storage = $storage;

        $this->cleanStorage();
    }

    public function testUnknownTaskFails(): void
    {
        $this->tester->execute(['id' => 'does.not.exist']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Task "does.not.exist" is not registered', $display);
        self::assertStringContainsString('deploytasks:status', $display);
    }

    public function testPendingTaskRendersMetadataAndPendingNote(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('test.simple', $display);
        self::assertStringContainsString('A simple test task', $display);
        self::assertStringContainsString('pending', $display);
    }

    public function testExecutedTaskRendersEveryStoredField(): void
    {
        $error = 'Long failure explanation with database DSN and stack trace context';
        $this->storage->save(new TaskExecution(
            'test.simple', TaskStatus::Failed, new \DateTimeImmutable('2026-04-23 10:00:00'), $error,
        ));

        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('failed', $display);
        self::assertStringContainsString('2026-04-23 10:00:00', $display);
        self::assertStringContainsString($error, $display);
    }

    public function testErrorRowStripsAnsiEscapeSequences(): void
    {
        $this->storage->save(new TaskExecution(
            'test.simple', TaskStatus::Failed, new \DateTimeImmutable(), "boom\x1b[2J",
        ));

        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('boom', $display);
        self::assertStringNotContainsString("\x1b", $display);
    }

    public function testShowIncludesCrossReferenceHints(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('deploytasks:reset test.simple', $display);
        self::assertStringContainsString('deploytasks:run --id=test.simple', $display);
    }

    // --- Mutant-killing tests ---

    // Mutant 70 (MethodCallRemoval:73) — title is printed with the task ID
    public function testTitleDisplaysDeployTaskPrefix(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        // io->title() wraps text with decoration; must contain the task ID with the prefix
        self::assertStringContainsString('Deploy task: test.simple', $display);
    }

    // Mutant 71 (ArrayItemRemoval:75) — 'ID' row present in definition list
    public function testDefinitionListContainsIdRow(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('ID', $display);
        self::assertStringContainsString('test.simple', $display);
    }

    // Mutant 72 (ArrayItemRemoval:76) — 'Class' row present in definition list
    public function testDefinitionListContainsClassRow(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Class', $display);
        // SimpleTask FQCN must appear
        self::assertStringContainsString('SimpleTask', $display);
    }

    // Mutant 73 (ArrayItemRemoval:78) — 'Declared groups' row present in definition list
    public function testDefinitionListContainsDeclaredGroupsRow(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Declared groups', $display);
        // test.simple has no groups → "default slot only"
        self::assertStringContainsString('default slot only', $display);
    }

    // Mutant 73 for grouped task — groups value listed
    public function testDefinitionListContainsDeclaredGroupsForGroupedTask(): void
    {
        $this->tester->execute(['id' => 'test.multi_group']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Declared groups', $display);
        self::assertStringContainsString('predeploy', $display);
        self::assertStringContainsString('postdeploy', $display);
    }

    // Mutant 74 (MethodCallRemoval:89) — "Execution records" section heading appears when records exist
    public function testExecutionRecordsSectionHeadingAppears(): void
    {
        $this->storage->save(new TaskExecution(
            'test.simple', TaskStatus::Ran, new \DateTimeImmutable('2026-05-01 12:00:00'),
        ));

        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Execution records', $this->tester->getDisplay());
    }

    // Mutant 75 (MethodCallRemoval:95) — "Related commands" section heading appears
    public function testRelatedCommandsSectionHeadingAppears(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Related commands', $this->tester->getDisplay());
    }

    // Mutant 76+77 (MatchArmRemoval:106) — all three TaskStatus enum arms produce correct label
    public function testRanStatusRendersInfoTag(): void
    {
        $this->storage->save(new TaskExecution(
            'test.simple', TaskStatus::Ran, new \DateTimeImmutable('2026-05-01 12:00:00'),
        ));

        $this->tester->execute(['id' => 'test.simple']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('ran', $display);
        // Must not show 'failed' or 'skipped' for a Ran record
        self::assertStringNotContainsString('failed', $display);
        self::assertStringNotContainsString('skipped', $display);
    }

    public function testSkippedStatusRendersCommentTag(): void
    {
        $this->storage->save(new TaskExecution(
            'test.simple', TaskStatus::Skipped, new \DateTimeImmutable('2026-05-01 12:00:00'),
        ));

        $this->tester->execute(['id' => 'test.simple']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('skipped', $display);
        self::assertStringNotContainsString('failed', $display);
    }

    public function testFailedStatusRendersErrorTag(): void
    {
        $this->storage->save(new TaskExecution(
            'test.simple', TaskStatus::Failed, new \DateTimeImmutable('2026-05-01 12:00:00'), 'boom',
        ));

        $this->tester->execute(['id' => 'test.simple']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('failed', $display);
        self::assertStringNotContainsString('skipped', $display);
    }

    // Mutant 78 (ArrayItemRemoval:112) — 'Group' row present in execution record definition list
    public function testExecutionRecordContainsGroupRow(): void
    {
        $this->storage->save(new TaskExecution(
            'test.simple', TaskStatus::Ran, new \DateTimeImmutable('2026-05-01 12:00:00'),
        ));

        $this->tester->execute(['id' => 'test.simple']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Group', $display);
        // test.simple has no group → default slot label
        self::assertStringContainsString('(default slot)', $display);
    }

    // Mutant 79 (Coalesce:113) — group ?? DEFAULT_SLOT_LABEL: null group uses default label, not vice versa
    public function testNullGroupUsesDefaultSlotLabel(): void
    {
        // test.simple has no group (null) → should display "(default slot)", not the null itself
        $this->storage->save(new TaskExecution(
            'test.simple', TaskStatus::Ran, new \DateTimeImmutable('2026-05-01 12:00:00'),
        ));

        $this->tester->execute(['id' => 'test.simple']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('(default slot)', $display);
    }

    // Mutant 79 inverse — when group is set, the actual group name appears (not the default label)
    public function testNonNullGroupDisplaysActualGroupName(): void
    {
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable('2026-05-01 12:00:00'), null, 'predeploy',
        ));

        $this->tester->execute(['id' => 'test.multi_group']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('predeploy', $display);
        self::assertStringNotContainsString('(default slot)', $display);
    }

    // Mutant 80 (ArrayItemRemoval:113) — same as 78, already covered by testExecutionRecordContainsGroupRow

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
