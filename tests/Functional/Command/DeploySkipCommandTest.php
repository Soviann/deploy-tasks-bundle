<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasks\Bundle\Command\DeployTasksSkipCommand;
use Soviann\DeployTasks\Contract\TaskStatus;
use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Soviann\DeployTasks\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksSkipCommand::class)]
final class DeploySkipCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('deploytasks:skip'));

        // Clean storage
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        foreach ($storage->all() as $execution) {
            $storage->remove($execution->id);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \restore_exception_handler();
    }

    public function testSkipTask(): void
    {
        $this->tester->execute(['id' => 'test.simple']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('marked as skipped', $this->tester->getDisplay());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $execution = $storage->get('test.simple');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Skipped, $execution->status);
    }

    public function testSkipUnknownTask(): void
    {
        $this->tester->execute(['id' => 'nonexistent']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        self::assertStringContainsString('not registered', $this->tester->getDisplay());
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
