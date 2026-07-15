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

    public function testSkipGroupedTaskRequiresGroupFlag(): void
    {
        $this->tester->execute(['id' => 'test.predeploy']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('specify --group', $display);
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
