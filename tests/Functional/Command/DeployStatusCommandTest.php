<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasks\Bundle\Command\DeployTasksStatusCommand;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksStatusCommand::class)]
final class DeployStatusCommandTest extends KernelTestCase
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
        $this->tester = new CommandTester($application->find('deploytasks:status'));

        // Clean storage
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        foreach ($storage->all() as $execution) {
            $storage->remove($execution->id);
        }
    }

    public function testStatusShowsAllTasks(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('test.simple', $display);
        self::assertStringContainsString('test.prod_only', $display);
        self::assertStringContainsString('pending', $display);
    }

    public function testNoStateFlag(): void
    {
        $this->tester->execute(['--no-state' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('test.simple', $display);
        self::assertStringNotContainsString('pending', $display);
    }

    public function testStatusShowsTaskCount(): void
    {
        $this->tester->execute([]);

        self::assertStringContainsString('5 task(s) registered', $this->tester->getDisplay());
    }

    public function testStatusShowsAllExecutionStates(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        // Set up distinct states in storage
        $storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));
        $storage->save(new TaskExecution('test.prod_only', TaskStatus::Skipped, new \DateTimeImmutable()));

        $this->tester->execute([]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('ran', $display);
        self::assertStringContainsString('skipped', $display);
        // PrioritizedTask, SkippingTask, MultiEnvTask have no record — shown as pending
        self::assertStringContainsString('pending', $display);
    }
}
