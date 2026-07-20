<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\CommandMessages;
use Soviann\DeployTasksBundle\Command\DeployTasksResetCommand;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksResetCommand::class)]
final class DeployResetCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private TaskStorageInterface $storage;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->application = new Application(self::kernel());
        $this->tester = new CommandTester($this->application->find('deploytasks:reset'));

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $this->storage = $storage;

        $this->cleanStorage();
    }

    public function testResetTask(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['yes']);
        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('has been reset', $this->tester->getDisplay());
        self::assertFalse($this->storage->has('test.simple'));
    }

    public function testResetTaskAborted(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['no']);
        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testResetTaskAbortedOnEmptyConfirmation(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['']);
        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testResetWithNoInteractionWithoutForceRefuses(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['id' => 'test.simple', '--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString('Refusing to run destructive command', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testResetWithNoInteractionAndForce(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['id' => 'test.simple', '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('has been reset', $this->tester->getDisplay());
        self::assertFalse($this->storage->has('test.simple'));
    }

    public function testResetRejectsRemovedYesOption(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('The "--yes" option does not exist.');

        $this->tester->execute(['id' => 'test.simple', '--yes' => true], ['interactive' => false]);
    }

    public function testResetInteractiveYes(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['yes']);
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertFalse($this->storage->has('test.simple'));
    }

    public function testResetInteractiveNo(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['no']);
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testResetAlreadyPending(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('already pending', $this->tester->getDisplay());
    }

    public function testResetUnknownTask(): void
    {
        $this->tester->execute(['id' => 'nonexistent']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString(
            \sprintf(CommandMessages::UNKNOWN_TASK, 'nonexistent'),
            (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay()),
        );
    }

    public function testResetWithoutGroupRemovesAllSlots(): void
    {
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy',
        ));

        $this->tester->execute(
            ['id' => 'test.multi_group', '--force' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertFalse($this->storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testResetWithoutGroupConfirmsOnceNamingRecordedSlots(): void
    {
        // All-slots UX (Task 3.4): the bare-reset confirmation must name what it
        // is about to remove — every recorded slot — and a single "yes" clears
        // them all.
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy',
        ));

        $this->tester->setInputs(['yes']);
        $this->tester->execute(['id' => 'test.multi_group'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString(
            'All recorded slots (postdeploy, predeploy) will be cleared',
            $this->tester->getDisplay(),
        );
        self::assertFalse($this->storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testResetPromptNamesDefaultSlotWhenOnlyDefaultRecordExists(): void
    {
        // The default (null) slot is listed as "default" so an ungrouped task's
        // prompt still says exactly which record is removed.
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['no']);
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString(
            'All recorded slots (default) will be cleared',
            $this->tester->getDisplay(),
        );
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testResetWithGroupRemovesSingleSlot(): void
    {
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy',
        ));

        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => 'predeploy', '--force' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertFalse($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testHelpCrossReferencesRun(): void
    {
        $help = $this->application->find('deploytasks:reset')->getHelp();

        self::assertStringContainsString('deploytasks:run --id=', $help);
    }

    public function testResetWithGroupNotDeclaredOnTaskEmitsWarning(): void
    {
        // Kills NotIdentical→Identical (#35), LogicalNot (#36), LogicalAndAllSubExprNegation (#37),
        // LogicalAndNegation (#38) mutations on the warning condition (line 92).
        // test.simple has no groups declared (groupsOf returns null), so passing --group triggers
        // the "group not declared" warning only when declared IS non-null.
        // test.predeploy HAS a group declared; passing a different group must emit the warning.
        $this->storage->save(new TaskExecution(
            'test.predeploy', TaskStatus::Ran, new \DateTimeImmutable(), null, 'wronggroup',
        ));

        $this->tester->execute(
            ['id' => 'test.predeploy', '--group' => 'wronggroup', '--force' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('not declared', $display);
        self::assertStringContainsString('Proceeding to clean any stale row anyway', $display);
    }

    public function testResetWithGroupDeclaredOnTaskDoesNotWarn(): void
    {
        // Companion: when the group IS declared, no warning must appear.
        // Kills logical inversions that would warn unconditionally.
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));

        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => 'predeploy', '--force' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringNotContainsString('not declared', $display);
    }

    public function testGroupResetConfirmDefaultIsFalse(): void
    {
        // Kills FalseValue (#39, line 103) and LogicalNot (#40) mutations on the confirm() default.
        // If default is mutated to true, pressing Enter (empty input) confirms instead of aborting.
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));

        $this->tester->setInputs(['']); // empty = accept default
        $this->tester->execute(['id' => 'test.multi_group', '--group' => 'predeploy'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted', $this->tester->getDisplay());
        // Storage must be unchanged.
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
    }

    public function testFindExecutedSlotsReturnsAllSlotsNotJustFirst(): void
    {
        // Kills ArrayOneItem (#41, line 149): mutation makes findExecutedSlots() return only the
        // first slot when >1 exists. The "already pending" branch is skipped only when $slots is
        // non-empty, so if only one slot is returned when two exist, the command still works for
        // the first slot — but the success message must reflect ALL slots being reset.
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy',
        ));

        $this->tester->execute(
            ['id' => 'test.multi_group', '--force' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        // Both slots must be gone.
        self::assertFalse($this->storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($this->storage->has('test.multi_group', 'postdeploy'));
        // Success message refers to "all slots".
        self::assertStringContainsString('across all slots', $this->tester->getDisplay());
    }

    public function testResetWithDeclaredGroupWithoutExecutionRecordReportsAlreadyPending(): void
    {
        // 'predeploy' IS declared on test.multi_group, so the undeclared-group warning
        // branch is not taken; with nothing saved, the (task, group) slot has no record
        // and the command must short-circuit with the "already pending" note.
        $this->tester->execute(['id' => 'test.multi_group', '--group' => 'predeploy']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('no execution record for group "predeploy"', $display);
        self::assertStringContainsString('already pending', $display);
        self::assertStringNotContainsString('not declared', $display);
        // Storage must remain untouched.
        self::assertSame([], $this->storage->all());
    }

    public function testResetRepeatedGroupOptionRemovesEveryTargetedSlotWithOneConfirmation(): void
    {
        // Phase 5 arity change: --group is repeatable. A single "yes" must
        // clear every requested slot — one confirmation covers the whole list
        // (a second prompt would exhaust the input stream and fail).
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy',
        ));

        $this->tester->setInputs(['yes']);
        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => ['predeploy', 'postdeploy']],
            ['interactive' => true],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('in groups "predeploy", "postdeploy"?', $display);
        self::assertStringContainsString('has been reset in groups "predeploy", "postdeploy"', $display);
        self::assertFalse($this->storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testResetRepeatedGroupOptionDedupesIdenticalValues(): void
    {
        // --group=predeploy --group=predeploy must behave exactly like a single
        // --group=predeploy: one confirmation naming the slot once (singular
        // wording, not the plural "in groups ..." form a non-deduped
        // ['predeploy', 'predeploy'] pair would produce), a single reset, and
        // no duplicate rows/output.
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));

        $this->tester->setInputs(['yes']);
        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => ['predeploy', 'predeploy']],
            ['interactive' => true],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('Reset task "test.multi_group" in group "predeploy"?', $display);
        self::assertStringNotContainsString('in groups', $display);
        self::assertStringContainsString(
            'has been reset in group "predeploy" and will run again on next deploytasks:run --group=predeploy.',
            $display,
        );
        self::assertFalse($this->storage->has('test.multi_group', 'predeploy'));
    }

    public function testResetRepeatedGroupOptionResetsRecordedSlotAndNotesPendingOne(): void
    {
        // Mixed case: only predeploy has a record. The pending group is noted
        // "already pending", the confirmation names only the slot actually
        // being reset, and only that slot is removed.
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));

        $this->tester->setInputs(['yes']);
        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => ['predeploy', 'postdeploy']],
            ['interactive' => true],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('no execution record for group "postdeploy"', $display);
        self::assertStringContainsString('already pending', $display);
        self::assertStringContainsString('Reset task "test.multi_group" in group "predeploy"?', $display);
        self::assertFalse($this->storage->has('test.multi_group', 'predeploy'));
        self::assertSame([], $this->storage->all());
    }

    public function testResetRepeatedGroupOptionDeclineLeavesEveryRecordIntact(): void
    {
        // All-or-nothing: declining the single confirmation removes nothing —
        // a partial reset across the requested slots must be impossible.
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy',
        ));

        $this->tester->setInputs(['no']);
        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => ['predeploy', 'postdeploy']],
            ['interactive' => true],
        );

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testResetRejectsGroupListContainingMalformedNameBeforeAnyRemoval(): void
    {
        // One malformed name rejects the whole command before any storage
        // access: every record survives, including the validly-named one's.
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy',
        ));
        $this->storage->save(new TaskExecution(
            'test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy',
        ));

        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => ['predeploy', 'pre deploy'], '--force' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString('Invalid group name', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testResetRepeatedGroupOptionWithNoRecordsNotesEveryGroupAlreadyPending(): void
    {
        // When none of the requested slots has a record there is nothing to
        // confirm or remove: every group is noted already-pending and the
        // command succeeds without touching storage.
        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => ['predeploy', 'postdeploy'], '--force' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('no execution record for group "predeploy"', $display);
        self::assertStringContainsString('no execution record for group "postdeploy"', $display);
        self::assertSame([], $this->storage->all());
    }

    public function testMalformedGroupNameIsRejectedCleanly(): void
    {
        $this->tester->execute(
            ['id' => 'test.simple', '--group' => 'pre deploy', '--force' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString('Invalid group name', $this->tester->getDisplay());
        self::assertStringContainsString('pre deploy', $this->tester->getDisplay());
    }

    public function testMalformedGroupNameErrorStripsControlBytes(): void
    {
        // The rejected value is by definition untrusted — it just failed the
        // pattern — so echoing it back must strip control bytes, same as the
        // sibling commands do for their group-mismatch messages.
        $this->tester->execute(
            ['id' => 'test.simple', '--group' => "pre\x1b[2Jdeploy", '--force' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString('Invalid group name', $this->tester->getDisplay());
        self::assertStringNotContainsString("\x1b", $this->tester->getDisplay());
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
