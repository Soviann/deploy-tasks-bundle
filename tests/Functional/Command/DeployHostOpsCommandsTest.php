<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksResetHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupHostCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipHostCommand;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Soviann\DeployTasksBundle\Tests\Support\HostTasksKernelFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Host ops-plane parity: skip:host / reset:host / rollup:host manipulate the completion
 * log with the same exact-line (`grep -Fxq`) semantics as bin/deploy-tasks-host.sh.
 */
#[CoversClass(DeployTasksSkipHostCommand::class)]
#[CoversClass(DeployTasksResetHostCommand::class)]
#[CoversClass(DeployTasksRollupHostCommand::class)]
final class DeployHostOpsCommandsTest extends FunctionalTestCase
{
    private string $projectDir;
    private string $hostDir;
    private string $logPath;
    private Kernel $hostKernel;
    private Application $application;

    protected function setUp(): void
    {
        $this->projectDir = FilesystemTestHelper::tempDir('deploy-tasks-host-ops-');
        $this->hostDir = $this->projectDir.'/deploy/host-tasks';
        $this->logPath = $this->projectDir.'/.deploy-tasks-host.log';

        $this->hostKernel = HostTasksKernelFactory::boot($this->projectDir);
        $this->application = new Application($this->hostKernel);
    }

    protected function tearDown(): void
    {
        FilesystemTestHelper::cleanup($this->projectDir);
        HostTasksKernelFactory::cleanupAll();
        parent::tearDown();
    }

    // --- skip:host ---

    public function testSkipHostMarksScriptDoneOnConfirmation(): void
    {
        $this->makeScript('a');

        $tester = $this->tester('deploytasks:skip:host');
        $tester->setInputs(['yes']);
        $exitCode = $tester->execute(['id' => 'a']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('marked as done', $tester->getDisplay());
        self::assertSame(['a'], $this->logLines());
    }

    public function testSkipHostUnknownIdIsInvalid(): void
    {
        \mkdir($this->hostDir, 0o755, true);

        $tester = $this->tester('deploytasks:skip:host');
        $exitCode = $tester->execute(['id' => 'nonexistent']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('nonexistent', $tester->getDisplay());
        self::assertSame([], $this->logLines());
    }

    public function testSkipHostAlreadyLoggedIsNoopSuccess(): void
    {
        $this->makeScript('a');
        \file_put_contents($this->logPath, "a\n");

        $tester = $this->tester('deploytasks:skip:host');
        $exitCode = $tester->execute(['id' => 'a']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('already done', $tester->getDisplay());
        // No duplicate line appended.
        self::assertSame(['a'], $this->logLines());
    }

    public function testSkipHostRefusesOnDeclinedConfirmation(): void
    {
        $this->makeScript('a');

        $tester = $this->tester('deploytasks:skip:host');
        $tester->setInputs(['no']);
        $exitCode = $tester->execute(['id' => 'a'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Aborted', $tester->getDisplay());
        self::assertSame([], $this->logLines());
    }

    public function testSkipHostFailsLoudlyWhenLogIsUnwritable(): void
    {
        $this->makeScript('a');
        \mkdir($this->logPath, 0o755, true); // a directory where the log file should be

        $tester = $this->tester('deploytasks:skip:host');

        $this->expectException(IOException::class);
        $tester->execute(['id' => 'a'], ['interactive' => false]);
    }

    public function testSkipHostRejectsTraversalId(): void
    {
        \mkdir($this->hostDir, 0o755, true);
        // Reachable as "../evil" from the host dir — without validation the skip would
        // accept it and poison the log with a path-traversal id.
        \touch($this->projectDir.'/deploy/evil.sh');

        $tester = $this->tester('deploytasks:skip:host');
        $exitCode = $tester->execute(['id' => '../evil'], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid host task id', $tester->getDisplay());
        self::assertSame([], $this->logLines());
    }

    // --- reset:host ---

    public function testResetHostRemovesExactLine(): void
    {
        $this->makeScript('a');
        $this->makeScript('a_extra');
        \file_put_contents($this->logPath, "a\na_extra\n");

        $tester = $this->tester('deploytasks:reset:host');
        $exitCode = $tester->execute(['id' => 'a', '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('has been reset', $tester->getDisplay());
        self::assertSame(['a_extra'], $this->logLines());
    }

    public function testResetHostAbsentFromLogReportsAlreadyPending(): void
    {
        $this->makeScript('a');

        $tester = $this->tester('deploytasks:reset:host');
        $exitCode = $tester->execute(['id' => 'a']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('already pending', $tester->getDisplay());
        self::assertSame([], $this->logLines());
    }

    public function testResetHostRequiresConfirmation(): void
    {
        $this->makeScript('a');
        \file_put_contents($this->logPath, "a\n");

        $tester = $this->tester('deploytasks:reset:host');
        $tester->setInputs(['no']);
        $exitCode = $tester->execute(['id' => 'a'], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Aborted', $tester->getDisplay());
        self::assertSame(['a'], $this->logLines());
    }

    public function testResetHostWithForceSkipsConfirmation(): void
    {
        $this->makeScript('a');
        \file_put_contents($this->logPath, "a\n");

        $tester = $this->tester('deploytasks:reset:host');
        $exitCode = $tester->execute(['id' => 'a', '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([], $this->logLines());
    }

    public function testResetHostRemovesDuplicateLines(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        \file_put_contents($this->logPath, "a\na\nb\n");

        $tester = $this->tester('deploytasks:reset:host');
        $exitCode = $tester->execute(['id' => 'a', '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame("b\n", \file_get_contents($this->logPath));
    }

    public function testResetHostYesAliasSkipsConfirmation(): void
    {
        $this->makeScript('a');
        \file_put_contents($this->logPath, "a\n");

        $tester = $this->tester('deploytasks:reset:host');
        $exitCode = $tester->execute(['id' => 'a', '--yes' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([], $this->logLines());
    }

    public function testResetHostRefusesNonInteractiveWithoutForce(): void
    {
        $this->makeScript('a');
        \file_put_contents($this->logPath, "a\n");

        $tester = $this->tester('deploytasks:reset:host');
        $exitCode = $tester->execute(['id' => 'a', '--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Refusing to run destructive command', $tester->getDisplay());
        self::assertSame(['a'], $this->logLines());
    }

    public function testResetHostUnknownScriptIsInvalid(): void
    {
        \mkdir($this->hostDir, 0o755, true);

        $tester = $this->tester('deploytasks:reset:host');
        $exitCode = $tester->execute(['id' => 'ghost', '--force' => true], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('ghost', $tester->getDisplay());
        self::assertSame([], $this->logLines());
    }

    public function testResetHostStaleLogEntryWarnsAndStillResets(): void
    {
        \mkdir($this->hostDir, 0o755, true);
        // Completion record survives its script's deletion — reset must clean it up, not
        // pretend the id is unknown, but it must say so.
        \file_put_contents($this->logPath, "ghost\n");

        $tester = $this->tester('deploytasks:reset:host');
        $exitCode = $tester->execute(['id' => 'ghost', '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = (string) \preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString(
            \sprintf('No ghost.sh in %s — removing the stale completion record anyway.', $this->hostDir),
            $display,
        );
        self::assertSame([], $this->logLines());
    }

    public function testResetHostRejectsInvalidId(): void
    {
        \mkdir($this->hostDir, 0o755, true);
        // A poisoned log line must not be manipulable through an invalid id either.
        \file_put_contents($this->logPath, "../evil\n");

        $tester = $this->tester('deploytasks:reset:host');
        $exitCode = $tester->execute(['id' => '../evil', '--force' => true], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid host task id', $tester->getDisplay());
        self::assertSame(['../evil'], $this->logLines());
    }

    // --- rollup:host ---

    public function testRollupHostAppendsEveryPendingId(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        \file_put_contents($this->logPath, "a\n");

        $tester = $this->tester('deploytasks:rollup:host');
        $exitCode = $tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('marked 1 host task(s) as done', $tester->getDisplay());
        self::assertSame(['a', 'b'], $this->logLines());
    }

    public function testRollupHostEmptyDirWarns(): void
    {
        \mkdir($this->hostDir, 0o755, true);

        $tester = $this->tester('deploytasks:rollup:host');
        $exitCode = $tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = (string) \preg_replace('/\s+/', ' ', \strtolower($tester->getDisplay()));
        self::assertStringContainsString('nothing to roll up', $display);
        self::assertSame([], $this->logLines());
    }

    public function testRollupHostRequiresConfirmation(): void
    {
        $this->makeScript('a');

        $tester = $this->tester('deploytasks:rollup:host');
        $tester->setInputs(['no']);
        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Aborted', $tester->getDisplay());
        self::assertSame([], $this->logLines());
    }

    public function testRollupHostWithForceSkipsConfirmation(): void
    {
        $this->makeScript('a');

        $tester = $this->tester('deploytasks:rollup:host');
        $exitCode = $tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['a'], $this->logLines());
    }

    public function testRollupHostAllDoneSkipsConfirmationPrompt(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        \file_put_contents($this->logPath, "a\nb\n");

        $tester = $this->tester('deploytasks:rollup:host');
        // No setInputs(): the command must resolve to SUCCESS without ever
        // reading from stdin, proving the confirmation prompt was skipped
        // because the all-done check runs before it.
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Every host task is already marked as done — nothing to roll up.', $tester->getDisplay());
        self::assertSame(['a', 'b'], $this->logLines());
    }

    public function testRollupHostSkipsAndWarnsOnHostileBasename(): void
    {
        $this->makeScript('a');
        // Legal on POSIX, but appending it would split into two log lines and corrupt
        // the runner's grep -Fxq bookkeeping.
        \touch($this->hostDir.'/'."bad\nname.sh");

        $tester = $this->tester('deploytasks:rollup:host');
        $exitCode = $tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['a'], $this->logLines());
        self::assertStringContainsString('ignored', $tester->getDisplay());
    }

    public function testRollupHostFailsLoudlyWhenLogIsUnwritable(): void
    {
        $this->makeScript('a');
        \mkdir($this->logPath, 0o755, true); // a directory where the log file should be

        $tester = $this->tester('deploytasks:rollup:host');

        $this->expectException(IOException::class);
        $tester->execute(['--force' => true], ['interactive' => false]);
    }

    // --- lock contention (the runner's flock) ---

    public function testResetHostRefusesWhileHostRunHoldsTheLock(): void
    {
        $this->makeScript('a');
        \file_put_contents($this->logPath, "a\n");
        $lock = \fopen($this->projectDir.'/.deploy-tasks-host.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(\flock($lock, \LOCK_EX | \LOCK_NB));

        try {
            $tester = $this->tester('deploytasks:reset:host');
            $exitCode = $tester->execute(['id' => 'a', '--force' => true], ['interactive' => false]);

            self::assertSame(75, $exitCode);
            self::assertSame(['a'], $this->logLines());
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }
    }

    public function testSkipHostRefusesWhileHostRunHoldsTheLock(): void
    {
        $this->makeScript('a');
        $lock = \fopen($this->projectDir.'/.deploy-tasks-host.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(\flock($lock, \LOCK_EX | \LOCK_NB));

        try {
            $tester = $this->tester('deploytasks:skip:host');
            $exitCode = $tester->execute(['id' => 'a'], ['interactive' => false]);

            self::assertSame(75, $exitCode);
            self::assertStringContainsString('.deploy-tasks-host.lock', $tester->getDisplay());
            self::assertSame([], $this->logLines());
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }
    }

    public function testRollupHostRefusesWhileHostRunHoldsTheLock(): void
    {
        $this->makeScript('a');
        $lock = \fopen($this->projectDir.'/.deploy-tasks-host.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(\flock($lock, \LOCK_EX | \LOCK_NB));

        try {
            $tester = $this->tester('deploytasks:rollup:host');
            $exitCode = $tester->execute(['--force' => true], ['interactive' => false]);

            self::assertSame(75, $exitCode);
            self::assertStringContainsString('.deploy-tasks-host.lock', $tester->getDisplay());
            self::assertSame([], $this->logLines());
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }
    }

    // --- host dir missing (all three) ---

    public function testSkipHostMissingDirIsInvalidWithDocsPointer(): void
    {
        $tester = $this->tester('deploytasks:skip:host');
        $exitCode = $tester->execute(['id' => 'a']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('docs/host-tasks.md', $tester->getDisplay());
    }

    public function testResetHostMissingDirIsInvalidWithDocsPointer(): void
    {
        $tester = $this->tester('deploytasks:reset:host');
        $exitCode = $tester->execute(['id' => 'a', '--force' => true], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('docs/host-tasks.md', $tester->getDisplay());
    }

    public function testRollupHostMissingDirIsInvalidWithDocsPointer(): void
    {
        $tester = $this->tester('deploytasks:rollup:host');
        $exitCode = $tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('docs/host-tasks.md', $tester->getDisplay());
    }

    // --- newline discipline ---

    public function testSkipHostAppendKeepsLogGrepFxqClean(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        \file_put_contents($this->logPath, "a\n");

        $tester = $this->tester('deploytasks:skip:host');
        $tester->execute(['id' => 'b'], ['interactive' => false]);

        $raw = (string) \file_get_contents($this->logPath);
        self::assertSame("a\nb\n", $raw);
        self::assertStringNotContainsString("\r", $raw);
    }

    public function testRollupHostAppendKeepsLogGrepFxqClean(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');

        $tester = $this->tester('deploytasks:rollup:host');
        $tester->execute(['--force' => true], ['interactive' => false]);

        $raw = (string) \file_get_contents($this->logPath);
        self::assertSame("a\nb\n", $raw);
    }

    public function testResetHostRewriteKeepsLogGrepFxqClean(): void
    {
        $this->makeScript('a');
        $this->makeScript('b');
        \file_put_contents($this->logPath, "a\nb\n");

        $tester = $this->tester('deploytasks:reset:host');
        $tester->execute(['id' => 'a', '--force' => true], ['interactive' => false]);

        $raw = (string) \file_get_contents($this->logPath);
        self::assertSame("b\n", $raw);
    }

    public function testResetHostRemovingLastLineLeavesEmptyFile(): void
    {
        $this->makeScript('a');
        \file_put_contents($this->logPath, "a\n");

        $tester = $this->tester('deploytasks:reset:host');
        $tester->execute(['id' => 'a', '--force' => true], ['interactive' => false]);

        self::assertSame('', \file_get_contents($this->logPath));
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    private function makeScript(string $id): void
    {
        if (!\is_dir($this->hostDir)) {
            \mkdir($this->hostDir, 0o755, true);
        }
        \touch($this->hostDir.'/'.$id.'.sh');
    }

    private function tester(string $name): CommandTester
    {
        return new CommandTester($this->application->find($name));
    }

    /**
     * @return list<string>
     */
    private function logLines(): array
    {
        if (!\is_file($this->logPath)) {
            return [];
        }

        $lines = \file($this->logPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        return false !== $lines ? $lines : [];
    }
}
