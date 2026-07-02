<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupCommand;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\KernelConfig;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DeployTasksRollupCommand::class)]
final class DeployRollupDbalTest extends FunctionalTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::useConfigurableKernel(KernelConfig::dbalExtension(), KernelConfig::dbalServices());
        self::bootKernel();
        $this->cleanStorage();

        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:rollup'));
    }

    public function testRollupWithDbalStorageRunsInsideTransactionAndMarksAllSlotsRun(): void
    {
        $storage = $this->storage();
        // DbalStorage is transactional, so the rollup takes the transactional() branch.
        self::assertInstanceOf(TransactionalStorageInterface::class, $storage);

        $storage->save(new TaskExecution('stale.nonexistent', TaskStatus::Ran, new \DateTimeImmutable()));

        $this->tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        self::assertStringContainsString('Rolled up', $this->tester->getDisplay());

        // Every registered task slot is marked as run, the stale record is cleared.
        self::assertSame(TaskStatus::Ran, $storage->get('test.simple')?->status);
        self::assertSame(TaskStatus::Ran, $storage->get('test.transactional')?->status);
        self::assertFalse($storage->has('stale.nonexistent'));
    }
}
