<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksSkipCommand;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksSkipCommand::class)]
final class DeploySkipCommandTest extends FunctionalTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:skip'));
        $this->cleanStorage();
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

    public function testSkipGroupedTaskRequiresGroupFlag(): void
    {
        $this->tester->execute(['id' => 'test.predeploy']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
        $display = \preg_replace('/\s+/', ' ', $this->tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString('specify --group', $display);
    }

    public function testSkipMarksOnlyTargetSlot(): void
    {
        $this->tester->execute(['id' => 'test.multi_group', '--group' => 'predeploy']);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        self::assertTrue($storage->has('test.multi_group', 'predeploy'));
        self::assertFalse($storage->has('test.multi_group', 'postdeploy'));
        self::assertFalse($storage->has('test.multi_group'));

        $execution = $storage->get('test.multi_group', 'predeploy');
        self::assertNotNull($execution);
        self::assertSame(TaskStatus::Skipped, $execution->status);
    }

    public function testSkipUndeclaredGroupFails(): void
    {
        $this->tester->execute(['id' => 'test.predeploy', '--group' => 'postdeploy']);

        self::assertSame(Command::INVALID, $this->tester->getStatusCode());
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
