<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\DbalTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksRunCommand::class)]
final class DeployRunDbalTest extends FunctionalTestCase
{
    private CommandTester $runTester;
    private CommandTester $resetTester;
    private CommandTester $skipTester;

    protected function setUp(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $this->runTester = new CommandTester($application->find('deploytasks:run'));
        $this->resetTester = new CommandTester($application->find('deploytasks:reset'));
        $this->skipTester = new CommandTester($application->find('deploytasks:skip'));
    }

    public function testDbalStorageIsWired(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        self::assertInstanceOf(DbalStorage::class, $storage);
    }

    public function testDbalStorageAutoCreatesTable(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertSame([], $storage->all());
    }

    public function testRunCommandWithDbalStorage(): void
    {
        $this->runTester->execute([]);

        self::assertSame(Command::SUCCESS, $this->runTester->getStatusCode());
        self::assertStringContainsString('ran', $this->runTester->getDisplay());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertTrue($storage->has('test.simple'));
    }

    public function testTransactionalTaskRunsWithDbalStorage(): void
    {
        $this->runTester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $execution = $storage->get('test.transactional');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Ran, $execution->status);
    }

    public function testResetCommandWithDbalStorage(): void
    {
        $this->runTester->execute([]);

        $this->resetTester->execute(['id' => 'test.simple', '--no-interaction' => true]);

        self::assertSame(Command::SUCCESS, $this->resetTester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertFalse($storage->has('test.simple'), 'Reset must remove the execution record');
    }

    public function testSkipCommandWithDbalStorage(): void
    {
        $this->skipTester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->skipTester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $execution = $storage->get('test.simple');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Skipped, $execution->status);
    }

    protected static function getKernelClass(): string
    {
        return DbalTestKernel::class;
    }
}
