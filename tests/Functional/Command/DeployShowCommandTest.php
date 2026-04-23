<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksShowCommand;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksShowCommand::class)]
final class DeployShowCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;
    private TaskStorageInterface $storage;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:show'));

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $this->storage = $storage;

        $this->cleanStorage();
    }

    public function testUnknownTaskFails(): void
    {
        $this->tester->execute(['id' => 'does.not.exist']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Task "does.not.exist" is not registered', $display);
        self::assertStringContainsString('deploytasks:status', $display);
    }

    public function testPendingTaskRendersMetadataAndPendingNote(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('test.simple', $display);
        self::assertStringContainsString('A simple test task', $display);
        self::assertStringContainsString('pending', $display);
    }

    public function testExecutedTaskRendersEveryStoredField(): void
    {
        $error = 'Long failure explanation with database DSN and stack trace context';
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Failed, new \DateTimeImmutable('2026-04-23 10:00:00'), $error));

        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('failed', $display);
        self::assertStringContainsString('2026-04-23 10:00:00', $display);
        self::assertStringContainsString($error, $display);
    }

    public function testShowIncludesCrossReferenceHints(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('deploytasks:reset test.simple', $display);
        self::assertStringContainsString('deploytasks:run --id=test.simple', $display);
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
