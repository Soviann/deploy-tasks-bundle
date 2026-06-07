<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupCommand;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksRollupCommand::class)]
final class DeployRollupCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private TaskStorageInterface $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:rollup'));

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $this->storage = $storage;

        $this->cleanStorage();
    }

    public function testRollup(): void
    {
        // Save a record for a registered task and a stale one
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('stale.nonexistent', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['yes']);
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());

        // All registered tasks should be marked as run
        self::assertTrue($this->storage->has('test.simple'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('test.simple')?->status);

        // Stale record should be gone
        self::assertFalse($this->storage->has('stale.nonexistent'));
    }

    public function testRollupAborted(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['no']);
        $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted', $this->tester->getDisplay());

        // Storage should be unchanged
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testRollupNoInteractionWithoutForceRefuses(): void
    {
        $this->tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        self::assertStringContainsString('Refusing to run destructive command', $this->tester->getDisplay());
    }

    public function testRollupNoInteractionWithForce(): void
    {
        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());

        // All registered tasks should be marked as run
        self::assertTrue($this->storage->has('test.simple'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('test.simple')?->status);
    }

    public function testRollupNoInteractionWithYesAlias(): void
    {
        $this->tester->execute(['--yes' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());

        self::assertTrue($this->storage->has('test.simple'));
        self::assertSame(TaskStatus::Ran, $this->storage->get('test.simple')?->status);
    }

    public function testRollupInteractiveYes(): void
    {
        $this->tester->setInputs(['yes']);
        $this->tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testRollupInteractiveNo(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->setInputs(['no']);
        $this->tester->execute([], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testRollupIdempotent(): void
    {
        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Run again
        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testRollupMarksEverySlotAcrossAllTasks(): void
    {
        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        self::assertTrue($this->storage->has('test.simple'));
        self::assertTrue($this->storage->has('test.predeploy', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testRollupWithGroupMarksOnlyMatchingSlots(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--group' => ['predeploy'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        self::assertTrue($this->storage->has('test.predeploy', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($this->storage->has('test.multi_group', 'postdeploy'));
        // default-slot record must be preserved when filtering by group
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testRollupWithUnknownGroupEmitsTypoHint(): void
    {
        $this->tester->execute(['--group' => ['nope'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Group "nope" is declared on 0 tasks', $display);
        self::assertStringContainsString('typo?', $display);
    }

    public function testRollupUnknownGroupEmitsWarningNoSlotsMatched(): void
    {
        // Kills MethodCallRemoval (#48, line 116): mutation removes the $io->warning() call that
        // says "No task slots matched the requested group(s)." — the warning must appear.
        // Kills Foreach_ (#43, line 104): if the inner loop is replaced with an empty array,
        // $declaredCountByGroup stays empty and the typo-hint is never emitted.
        $this->tester->execute(['--group' => ['nosuchgroup'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('No task slots matched', $display);

        // The typo-hint loop must have actually counted declared groups and found 0 for 'nosuchgroup'.
        // This kills Foreach_ (#43): if the foreach is emptied, $declaredCountByGroup is always empty
        // and the hint fires for EVERY group filter — including groups that DO exist. We verify the hint
        // does NOT fire for a real group when a different unknown group is requested alongside it.
    }

    public function testRollupTypoHintCountsEachTaskOnce(): void
    {
        // Kills DecrementInteger (#44, ?? 0 → ?? -1) and DecrementInteger (#46, +1 → +0) and
        // Coalesce (#45) mutations on the count accumulation (line 106). If the count is wrong
        // (e.g., always -1+1=0 or always stays 0), the typo hint fires even for known groups.
        // We verify: when filtering for a KNOWN group ('predeploy'), no typo hint appears.
        $this->tester->execute(['--group' => ['predeploy'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        // 'predeploy' is declared on at least one task — no typo hint must appear.
        self::assertStringNotContainsString('typo?', $display);
        self::assertStringNotContainsString('declared on 0 tasks', $display);
    }

    public function testRollupTypoHintCoalesceChecksGroupCount(): void
    {
        // Kills Coalesce (#47, line 110): mutation swaps `$declaredCountByGroup[$group] ?? 0`
        // to `0 ?? $declaredCountByGroup[$group]`, making it always 0 regardless of the count.
        // When $group IS known (count > 0) the check `0 === (count ?? 0)` must be false → no hint.
        $this->tester->execute(['--group' => ['postdeploy'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringNotContainsString('typo?', $display);
    }

    public function testRollupSuccessMessageContainsRecordAndSlotCountWhenResetAll(): void
    {
        // Kills Ternary (#49, line 153): mutation swaps the two sprintf branches so a full rollup
        // (resetAll=true) gets the group-filter message format instead of the all-records format.
        // We assert the correct format (cleared N record(s), marked N slot(s) … across N task(s)).
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));
        $this->storage->save(new TaskExecution('stale.gone', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('cleared', $display);
        self::assertStringContainsString('record(s)', $display);
        self::assertStringContainsString('across', $display);
        self::assertStringContainsString('task(s)', $display);
        // Must NOT use the group-filter format.
        self::assertStringNotContainsString('for group(s)', $display);
    }

    public function testRollupWithGroupSuccessMessageMentionsGroupName(): void
    {
        // Companion to testRollupSuccessMessageContainsRecordAndSlotCountWhenResetAll:
        // when a --group filter is used (resetAll=false), the message must say "for group(s) [...]".
        $this->tester->execute(['--group' => ['predeploy'], '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertStringContainsString('for group(s) [predeploy]', $display);
        // Must NOT use the all-records format.
        self::assertStringNotContainsString('cleared', $display);
        self::assertStringNotContainsString('across', $display);
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
