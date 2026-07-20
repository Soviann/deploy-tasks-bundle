<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Command\ExitCodes;
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;
use Soviann\DeployTasksBundle\Tests\Fixtures\LeaseLosingLockFactory;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\KernelConfig;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

#[CoversClass(DeployTasksRunCommand::class)]
final class DeployRunLockCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        static::$class = TestKernel::class;
        self::$testKernelOptions = ['lockEnabled' => true];
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:run'));
        $this->cleanStorage();
    }

    public function testRunAllSucceedsWithExitCodeZero(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
    }

    public function testRunAllWithLockHeldExitsWithEzTempfail(): void
    {
        // Simulate a concurrent process by pre-acquiring the shared run lock.
        $lockFactory = self::getContainer()->get(LockFactory::class);
        \assert($lockFactory instanceof LockFactory);

        $heldLock = $lockFactory->createLock('soviann_deploy_tasks_run', 3600);
        self::assertTrue($heldLock->acquire(), 'Pre-flight lock must be acquirable for the test to be meaningful.');

        try {
            $this->tester->execute([]);
            self::assertSame(ExitCodes::EX_TEMPFAIL, $this->tester->getStatusCode());
        } finally {
            $heldLock->release();
        }
    }

    public function testRunOneWithLockHeldExitsWithExTempfail(): void
    {
        $lockFactory = self::getContainer()->get(LockFactory::class);
        \assert($lockFactory instanceof LockFactory);

        $heldLock = $lockFactory->createLock('soviann_deploy_tasks_run', 3600);
        self::assertTrue($heldLock->acquire(), 'Pre-flight lock must be acquirable for the test to be meaningful.');

        try {
            $this->tester->execute(['--id' => 'test.simple']);
            self::assertSame(ExitCodes::EX_TEMPFAIL, $this->tester->getStatusCode());
        } finally {
            $heldLock->release();
        }
    }

    public function testNonLockFailureExitsWithInvalid(): void
    {
        // Unknown task ID → INVALID (2), not EX_TEMPFAIL (75).
        $this->tester->execute(['--id' => 'nonexistent.task']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
    }

    public function testRunWarnsWhenLockEnabledButLockComponentUnavailable(): void
    {
        $extensionConfig = KernelConfig::customStorageExtension();
        $extensionConfig['lock'] = ['enabled' => true, 'ttl' => 3600];

        // framework.lock:false removes the `lock.factory` service even though symfony/lock
        // is installed — the compiler pass only cares whether the service exists, so this
        // reproduces the "package not installed" branch without an actual missing package.
        self::useConfigurableKernel(
            $extensionConfig,
            KernelConfig::customStorageServices(),
            frameworkConfig: ['lock' => false],
        );
        self::bootKernel();

        $tester = $this->runConsoleCommand('deploytasks:run');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('concurrent-run protection is inactive', $tester->getDisplay());
    }

    public function testMidRunLeaseLossReportsPartialWorkNotRunSkipped(): void
    {
        // A run that loses its lease AFTER executing tasks must not claim
        // "Run skipped: another process is already running." — work happened.
        // The summary has to name the early stop and the partial progress.
        $extensionConfig = KernelConfig::customStorageExtension();
        $extensionConfig['lock'] = ['enabled' => true, 'ttl' => 3600];

        $services = KernelConfig::customStorageServices();
        // Overrides the framework's lock.factory: acquire succeeds, every
        // between-task refresh throws — a deterministic mid-run lease loss.
        $services['lock.factory'] = ['class' => LeaseLosingLockFactory::class, 'public' => true];
        // The lease-loss path logs a warning; keep it off stderr (Infection).
        $services['logger'] = ['class' => NullLogger::class, 'public' => true];
        $services['test.task.one'] = ['class' => SimpleTask::class, 'args' => ['test.one', 'First'], 'tags' => ['soviann_deploy_tasks.task']];
        $services['test.task.two'] = ['class' => SimpleTask::class, 'args' => ['test.two', 'Second'], 'tags' => ['soviann_deploy_tasks.task']];

        self::useConfigurableKernel($extensionConfig, $services);
        self::bootKernel();

        $tester = $this->runConsoleCommand('deploytasks:run');

        self::assertSame(ExitCodes::EX_TEMPFAIL, $tester->getStatusCode());
        $display = (string) \preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Run stopped early', $display);
        self::assertStringContainsString('after 1 task(s)', $display);
        self::assertStringNotContainsString('already running', $display);

        $storage = self::getContainer()->get('test.custom_storage');
        \assert($storage instanceof InMemoryStorage);
        self::assertTrue($storage->has('test.one'), 'The task that ran before the lease loss keeps its record');
        self::assertFalse($storage->has('test.two'), 'No task may run after the lease is lost');
    }

    public function testRunDoesNotWarnWhenLockDisabled(): void
    {
        self::$testKernelOptions = [];
        self::bootKernel(); // default TestKernel: lock.enabled=false

        $tester = $this->runConsoleCommand('deploytasks:run');

        self::assertStringNotContainsString('concurrent-run protection', $tester->getDisplay());
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
