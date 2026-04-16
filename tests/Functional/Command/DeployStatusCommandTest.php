<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksStatusCommand;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksStatusCommand::class)]
final class DeployStatusCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('deploytasks:status'));
        $this->cleanStorage();
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

        self::assertStringContainsString('7 task(s) registered', $this->tester->getDisplay());
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

    public function testShowsOneRowPerTaskGroup(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();

        // Multi-group task should appear twice (once per declared slot)
        self::assertStringContainsString('test.multi_group', $display);
        self::assertStringContainsString('test.predeploy', $display);
        self::assertStringContainsString('predeploy', $display);
        self::assertStringContainsString('postdeploy', $display);

        // 5 default slots + 1 predeploy + 2 multi_group = 8 slots displayed
        self::assertStringContainsString('8 slot(s) displayed', $display);
    }

    public function testGroupFilterRestrictsDisplay(): void
    {
        $this->tester->execute(['--group' => ['predeploy']]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('test.predeploy', $display);
        self::assertStringContainsString('test.multi_group', $display);
        // Only predeploy slots: 2 slots
        self::assertStringContainsString('2 slot(s) displayed', $display);
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
