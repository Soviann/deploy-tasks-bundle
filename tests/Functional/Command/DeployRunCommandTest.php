<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasks\Bundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksRunCommand::class)]
final class DeployRunCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \restore_exception_handler();
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('deploytasks:run'));

        // Clean storage before each test
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        foreach ($storage->all() as $execution) {
            $storage->remove($execution->id);
        }
    }

    public function testRunAllTasks(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('ran', $this->tester->getDisplay());
    }

    public function testDryRun(): void
    {
        $this->tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('pending', $this->tester->getDisplay());

        // Verify no tasks were actually executed
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertEmpty($storage->all());
    }

    public function testForceRerunsAllTasks(): void
    {
        // First run
        $this->tester->execute([]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Force re-run all
        $this->tester->execute(['--force' => true]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('ran', $this->tester->getDisplay());
    }

    public function testIdRunsSingleTask(): void
    {
        $this->tester->execute(['--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertTrue($storage->has('test.simple'));
    }

    public function testIdSkipsAlreadyExecutedTask(): void
    {
        // First run
        $this->tester->execute(['--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Second run — already executed, skipped
        $this->tester->execute(['--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('already been executed', $this->tester->getDisplay());
    }

    public function testForceWithIdRerunsSingleTask(): void
    {
        // First run
        $this->tester->execute(['--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Force re-run single task
        $this->tester->execute(['--force' => true, '--id' => 'test.simple']);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
    }

    public function testRunAllAlreadyExecuted(): void
    {
        // First run
        $this->tester->execute([]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        // Second run — all already executed
        $this->tester->execute([]);
        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('nothing to run', $this->tester->getDisplay());
    }
}
