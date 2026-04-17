<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
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

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:reset'));

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

    public function testResetWithoutGroupRemovesAllSlots(): void
    {
        $this->storage->save(new TaskExecution('test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $this->storage->save(new TaskExecution('test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy'));

        $this->tester->execute(['id' => 'test.multi_group', '--no-interaction' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertFalse($this->storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($this->storage->has('test.multi_group', 'postdeploy'));
    }

    public function testResetWithGroupRemovesSingleSlot(): void
    {
        $this->storage->save(new TaskExecution('test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'predeploy'));
        $this->storage->save(new TaskExecution('test.multi_group', TaskStatus::Ran, new \DateTimeImmutable(), null, 'postdeploy'));

        $this->tester->execute(['id' => 'test.multi_group', '--group' => 'predeploy', '--no-interaction' => true]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertFalse($this->storage->has('test.multi_group', 'predeploy'));
        self::assertTrue($this->storage->has('test.multi_group', 'postdeploy'));
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
