<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\CommandMessages;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipCommand;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksSkipCommand::class)]
final class DeploySkipCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->application = new Application(self::kernel());
        $this->tester = new CommandTester($this->application->find('deploytasks:skip'));
        $this->cleanStorage();
    }

    public function testSkipTask(): void
    {
        $this->tester->setInputs(['yes']);
        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('marked as skipped', $this->tester->getDisplay());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $execution = $storage->get('test.simple');
        \assert(null !== $execution);
        self::assertSame(TaskStatus::Skipped, $execution->status);
        // A manual skip is not a run — no duration is recorded.
        self::assertNull($execution->durationMs);
    }

    public function testSkipWithNoInteraction(): void
    {
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('marked as skipped', $this->tester->getDisplay());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $execution = $storage->get('test.simple');
        \assert(null !== $execution);
        self::assertSame(TaskStatus::Skipped, $execution->status);
    }

    public function testSkipUnknownTask(): void
    {
        $this->tester->execute(['id' => 'nonexistent']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString(
            \sprintf(CommandMessages::UNKNOWN_TASK, 'nonexistent'),
            (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay()),
        );
    }

    public function testSkipWithoutGroupSkipsEveryDeclaredSlot(): void
    {
        // Flipped by the Phase 3 group-semantics change: bare skip on a grouped
        // task used to demand --group (group-required exception, class removed);
        // it now marks every declared slot as skipped.
        $this->tester->execute(['id' => 'test.multi_group'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertSame(TaskStatus::Skipped, $storage->get('test.multi_group', 'predeploy')?->status);
        self::assertSame(TaskStatus::Skipped, $storage->get('test.multi_group', 'postdeploy')?->status);
        self::assertFalse($storage->has('test.multi_group'), 'A grouped task must never record the default (null) slot');
    }

    public function testSkipWithoutGroupAbortsBeforeAnySlotWhenDeclined(): void
    {
        // Flipped from the interim per-slot prompts (3.3 seam) to the final
        // all-slots UX: ONE confirmation covers every targeted slot, and
        // declining it aborts with nothing saved — a partial skip across
        // slots must be impossible.
        $this->tester->setInputs(['no']);
        $this->tester->execute(['id' => 'test.multi_group'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertFalse($storage->has('test.multi_group', 'predeploy'), 'Declining the all-slots prompt must leave every slot untouched');
        self::assertFalse($storage->has('test.multi_group', 'postdeploy'));
    }

    public function testSkipWithoutGroupAsksOneConfirmationNamingAllSlots(): void
    {
        // Final all-slots UX (replaces the 3.3 interim per-slot prompts): a bare
        // skip on a multi-group task asks exactly ONE confirmation that lists
        // every targeted slot. A single "yes" answering it must complete the
        // whole batch — a second prompt would exhaust the input stream and fail.
        $this->tester->setInputs(['yes']);
        $this->tester->execute(['id' => 'test.multi_group'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString(
            'in all declared slots (predeploy, postdeploy)?',
            $this->tester->getDisplay(),
        );

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertSame(TaskStatus::Skipped, $storage->get('test.multi_group', 'predeploy')?->status);
        self::assertSame(TaskStatus::Skipped, $storage->get('test.multi_group', 'postdeploy')?->status);
    }

    public function testSkipAllSlotsPromptWarnsAboutExistingRanRecord(): void
    {
        // Task 1.9's overwrite guard folded into the all-slots prompt: when a
        // targeted slot holds a Ran record (real execution history), the single
        // confirmation must name that slot and warn the history gets erased.
        $ranAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->storage()->save(new TaskExecution('test.multi_group', TaskStatus::Ran, $ranAt, null, 'predeploy'));

        $this->tester->setInputs(['no']);
        $this->tester->execute(['id' => 'test.multi_group'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Slot "predeploy" already ran on 2026-01-01 10:00:00', $display);
        self::assertStringContainsString('erases its execution history', $display);
        self::assertStringContainsString('in all declared slots (predeploy, postdeploy)?', $display);

        // Declining leaves the Ran record intact and the other slot untouched.
        $execution = $this->storage()->get('test.multi_group', 'predeploy');
        \assert(null !== $execution);
        self::assertSame(TaskStatus::Ran, $execution->status);
        self::assertFalse($this->storage()->has('test.multi_group', 'postdeploy'));
    }

    public function testSkipAllSlotsPromptWarnsAboutExistingSkippedRecord(): void
    {
        // Non-Ran records get the milder overwrite warning, still naming the
        // slot and the stored status so the operator knows what is replaced.
        $skippedAt = new \DateTimeImmutable('2026-01-02 11:00:00');
        $this->storage()->save(new TaskExecution('test.multi_group', TaskStatus::Skipped, $skippedAt, null, 'postdeploy'));

        $this->tester->setInputs(['no']);
        $this->tester->execute(['id' => 'test.multi_group'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Slot "postdeploy" already has a "skipped" record from 2026-01-02 11:00:00', $display);
        self::assertStringNotContainsString('execution history', $display);
    }

    public function testSkipMarksOnlyTargetSlot(): void
    {
        $this->tester->setInputs(['yes']);
        $this->tester->execute(['id' => 'test.multi_group', '--group' => 'predeploy']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($storage->has('test.multi_group', 'postdeploy'));
        self::assertFalse($storage->has('test.multi_group'));

        $execution = $storage->get('test.multi_group', 'predeploy');
        \assert(null !== $execution);
        self::assertSame(TaskStatus::Skipped, $execution->status);
    }

    public function testSkipUndeclaredGroupFails(): void
    {
        $this->tester->execute(['id' => 'test.predeploy', '--group' => 'postdeploy']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
    }

    public function testSkipAbortedOnConfirmationDecline(): void
    {
        $this->tester->setInputs(['no']);
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted', $this->tester->getDisplay());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertFalse($storage->has('test.simple'));
    }

    public function testSkipAbortedOnEmptyConfirmation(): void
    {
        $this->tester->setInputs(['']);
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted', $this->tester->getDisplay());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertFalse($storage->has('test.simple'));
    }

    public function testHelpCrossReferencesRun(): void
    {
        $help = $this->application->find('deploytasks:skip')->getHelp();

        self::assertStringContainsString('deploytasks:run --id=', $help);
    }

    public function testSkipGroupOnUngroupedTaskReturnsInvalidAndEmitsError(): void
    {
        // Kills ReturnRemoval (#81, line 90): if the return INVALID is dropped, execution continues
        // past the error; we assert INVALID exit code so any code-path change is caught.
        // Kills MethodCallRemoval (#82, line 94): if the $io->error() call is removed, no error
        // message appears for an undeclared group — the error must be present in output.
        $this->tester->execute(['id' => 'test.simple', '--group' => 'predeploy']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('has no groups declared', $display);
        self::assertStringContainsString('cannot target --group=[predeploy]', $display);

        // Must NOT have saved anything to storage.
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertFalse($storage->has('test.simple'));
    }

    public function testSkipRepeatedGroupOptionIsDeduplicatedInMismatchMessage(): void
    {
        // Same dedup as deploytasks:run (RunOptions) and deploytasks:reset:
        // a repeated --group value must not surface twice in the mismatch
        // message — SlotResolver documents deduplicated input as its contract.
        $this->tester->execute(['id' => 'test.simple', '--group' => ['predeploy', 'predeploy']]);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('cannot target --group=[predeploy]', $display);
        self::assertStringNotContainsString('predeploy, predeploy', $display);
    }

    public function testSkipWithNoInteractionOptionSkipsPrompt(): void
    {
        // Pins the $input->isInteractive() guard: a non-interactive invocation must skip
        // the confirmation prompt entirely and proceed — kills mutants inverting or
        // removing the guard. (No setInputs() call — any prompt would block/fail on the
        // empty input stream.)
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('marked as skipped', $this->tester->getDisplay());
    }

    public function testSkipSuccessMessageForUngroupedTaskDoesNotMentionGroup(): void
    {
        // Kills Ternary (#85, line 116): mutation swaps the success message branches so a task
        // with no slot uses the group-mentioning format and vice versa.
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Task "test.simple" marked as skipped.', \strip_tags($display));
        // Must not contain 'in group' when there is no group slot.
        self::assertStringNotContainsString('in group', $display);
    }

    public function testSkipSuccessMessageForGroupedTaskMentionsGroupName(): void
    {
        // Companion to testSkipSuccessMessageForUngroupedTaskDoesNotMentionGroup:
        // when a slot is targeted, the message must say 'in group "predeploy"'.
        $this->tester->setInputs(['yes']);
        $this->tester->execute(['id' => 'test.predeploy', '--group' => 'predeploy']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('in group "predeploy"', \strip_tags($display));
    }

    public function testSkipConfirmPromptForGroupedTaskMentionsGroupName(): void
    {
        // Kills Ternary (#84, line 103): mutation swaps the confirmation prompt — a grouped-task
        // skip must show the prompt that includes the group name, not the ungrouped variant.
        $this->tester->setInputs(['no']); // decline so we can inspect the prompt
        $this->tester->execute(['id' => 'test.predeploy', '--group' => 'predeploy'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        // The prompt for grouped task must include the group name.
        self::assertStringContainsString('in group "predeploy"', $display);
    }

    public function testSkipConfirmPromptForUngroupedTaskDoesNotMentionGroup(): void
    {
        // Companion: the ungrouped confirm prompt must NOT mention any group.
        $this->tester->setInputs(['no']);
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringNotContainsString('in group', $display);
    }

    public function testSkipOfAlreadyRanSlotWarnsAndAbortsOnDecline(): void
    {
        $ranAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->storage()->save(new TaskExecution('test.simple', TaskStatus::Ran, $ranAt));

        $this->tester->setInputs(['no']);
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('already ran', $display);
        self::assertStringContainsString('Aborted', $display);

        $execution = $this->storage()->get('test.simple');
        \assert(null !== $execution);
        self::assertSame(TaskStatus::Ran, $execution->status);
        self::assertEquals($ranAt, $execution->executedAt);
    }

    public function testSkipOfAlreadyRanSlotAbortsOnBareEnter(): void
    {
        $ranAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->storage()->save(new TaskExecution('test.simple', TaskStatus::Ran, $ranAt));

        $this->tester->setInputs(['']);
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());

        $execution = $this->storage()->get('test.simple');
        \assert(null !== $execution);
        self::assertSame(TaskStatus::Ran, $execution->status);
    }

    public function testSkipOfAlreadyRanSlotProceedsOnConfirmation(): void
    {
        $ranAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->storage()->save(new TaskExecution('test.simple', TaskStatus::Ran, $ranAt));

        $this->tester->setInputs(['yes']);
        $this->tester->execute(['id' => 'test.simple'], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $execution = $this->storage()->get('test.simple');
        \assert(null !== $execution);
        self::assertSame(TaskStatus::Skipped, $execution->status);
    }

    public function testSkipOfAlreadyRanSlotProceedsUnderNoInteraction(): void
    {
        $ranAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->storage()->save(new TaskExecution('test.simple', TaskStatus::Ran, $ranAt));

        $this->tester->execute(['id' => 'test.simple'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $execution = $this->storage()->get('test.simple');
        \assert(null !== $execution);
        self::assertSame(TaskStatus::Skipped, $execution->status);
    }

    public function testSkipRepeatedGroupOptionSkipsEveryTargetedSlot(): void
    {
        // Phase 5 arity change: --group is repeatable, so one invocation can
        // target several specific slots at once.
        $this->tester->setInputs(['yes']);
        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => ['predeploy', 'postdeploy']],
            ['interactive' => true],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        self::assertSame(TaskStatus::Skipped, $this->storage()->get('test.multi_group', 'predeploy')?->status);
        self::assertSame(TaskStatus::Skipped, $this->storage()->get('test.multi_group', 'postdeploy')?->status);
        self::assertFalse($this->storage()->has('test.multi_group'), 'An explicit --group list must never touch the default slot');

        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('marked as skipped in groups "predeploy", "postdeploy"', $display);
    }

    public function testSkipRepeatedGroupOptionAsksOneConfirmationNamingTheTargetedGroups(): void
    {
        // A single "yes" must complete the whole batch — a second prompt would
        // exhaust the input stream and fail. The prompt names the requested
        // groups without claiming "all declared slots": the selection is
        // explicit, so it may be a subset of the declared ones.
        $this->tester->setInputs(['yes']);
        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => ['predeploy', 'postdeploy']],
            ['interactive' => true],
        );

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('in groups "predeploy", "postdeploy"?', $display);
        self::assertStringNotContainsString('all declared slots', $display);
    }

    public function testSkipRejectsGroupListContainingUndeclaredGroup(): void
    {
        // One undeclared group in the list rejects the whole command before
        // anything is saved: no partial skip.
        $this->tester->execute(
            ['id' => 'test.multi_group', '--group' => ['predeploy', 'nope']],
            ['interactive' => false],
        );

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertFalse($this->storage()->has('test.multi_group', 'predeploy'));
        self::assertFalse($this->storage()->has('test.multi_group', 'postdeploy'));
    }

    public function testSkipOfAlreadyRanGroupedSlotWarnsAndPreservesOtherSlot(): void
    {
        // Grouped slots must get the same overwrite guard as the default slot.
        $ranAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->storage()->save(new TaskExecution('test.multi_group', TaskStatus::Ran, $ranAt, null, 'predeploy'));

        $this->tester->setInputs(['no']);
        $this->tester->execute(['id' => 'test.multi_group', '--group' => 'predeploy'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('in group "predeploy"', $display);
        self::assertStringContainsString('already ran', $display);

        $execution = $this->storage()->get('test.multi_group', 'predeploy');
        \assert(null !== $execution);
        self::assertSame(TaskStatus::Ran, $execution->status);
        self::assertFalse($this->storage()->has('test.multi_group', 'postdeploy'));
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
