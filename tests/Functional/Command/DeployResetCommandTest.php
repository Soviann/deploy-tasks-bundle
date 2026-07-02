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

    public function testResetWithNoInteractionAndYesAlias(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['id' => 'test.simple', '--yes' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('has been reset', $this->tester->getDisplay());
        self::assertFalse($this->storage->has('test.simple'));
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

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
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

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
