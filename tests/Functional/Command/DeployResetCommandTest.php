<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasks\Bundle\Command\DeployTasksResetCommand;
use Soviann\DeployTasks\Contract\TaskExecution;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksResetCommand::class)]
final class DeployResetCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private TaskStorageInterface $storage;

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
        $this->tester = new CommandTester($application->find('deploytasks:reset'));

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $this->storage = $storage;

        // Clean storage
        foreach ($this->storage->all() as $execution) {
            $this->storage->remove($execution->id);
        }
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

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Aborted', $this->tester->getDisplay());
        self::assertTrue($this->storage->has('test.simple'));
    }

    public function testResetWithNoInteraction(): void
    {
        $this->storage->save(new TaskExecution('test.simple', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['id' => 'test.simple', '--no-interaction' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('has been reset', $this->tester->getDisplay());
        self::assertFalse($this->storage->has('test.simple'));
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
        self::assertStringContainsString('not registered', $this->tester->getDisplay());
    }
}
