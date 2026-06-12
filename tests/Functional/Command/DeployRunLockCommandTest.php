<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
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
            self::assertSame(DeployTasksRunCommand::EX_TEMPFAIL, $this->tester->getStatusCode());
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
            self::assertSame(DeployTasksRunCommand::EX_TEMPFAIL, $this->tester->getStatusCode());
        } finally {
            $heldLock->release();
        }
    }

    public function testNonLockFailureExitsWithFailure(): void
    {
        // Unknown task ID → FAILURE (1), not EX_TEMPFAIL (75).
        $this->tester->execute(['--id' => 'nonexistent.task']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
