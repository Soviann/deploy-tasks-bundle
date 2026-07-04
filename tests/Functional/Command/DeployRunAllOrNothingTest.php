<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Command\DeployTasksRunCommand;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Fixtures\FailingTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\KernelConfig;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Command-level behavior of an all_or_nothing abort: the run command renders a
 * rolled-back summary (failing task, nothing-persisted notice, cause) and exits
 * FAILURE instead of letting AllOrNothingFailureException escape to the console.
 */
#[CoversClass(DeployTasksRunCommand::class)]
final class DeployRunAllOrNothingTest extends FunctionalTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $extension = [
            'storage' => [
                'type' => 'database',
                'database' => [
                    'connection' => 'default',
                    'table' => 'deploy_task_executions',
                    'transactional' => true,
                    'all_or_nothing' => true,
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ];

        self::useConfigurableKernel($extension, [
            'doctrine.dbal.default_connection' => KernelConfig::sqliteConnection(),
            'test.task.simple' => [
                'class' => SimpleTask::class,
                'args' => ['test.simple', 'A simple test task'],
                'tags' => ['soviann_deploy_tasks.task'],
            ],
            'test.task.failing' => [
                'class' => FailingTask::class,
                'tags' => ['soviann_deploy_tasks.task'],
            ],
            // Failure-path kernels must not log to stderr — Infection SIGTERMs the
            // initial PHPUnit run on the first stderr byte (see bundle GOTCHAS).
            'logger' => ['class' => NullLogger::class, 'public' => true],
        ]);
        self::bootKernel();
        $this->cleanStorage();

        $application = new Application(self::kernel());
        $this->tester = new CommandTester($application->find('deploytasks:run'));
    }

    public function testAllOrNothingFailureRendersRolledBackSummary(): void
    {
        $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        // Normalized: SymfonyStyle wraps the error block at the terminal width,
        // which splits multi-word phrases on narrow CI runners.
        $display = \preg_replace('/\s+/', ' ', $this->tester->getDisplay()) ?? '';
        self::assertStringContainsString('test.failing', $display);
        self::assertStringContainsString('rolled back', $display);
        self::assertStringContainsString('no changes were persisted', $display);
        self::assertStringContainsString('Task failed!', $display);
    }

    public function testAllOrNothingFailureLeavesNoPersistedRecords(): void
    {
        $this->tester->execute([]);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertSame([], $storage->all(), 'the rollback must void every record of the aborted run');
    }

    public function testSingleTaskFailureRendersRolledBackSummary(): void
    {
        $this->tester->execute(['--id' => 'test.failing']);

        self::assertSame(Command::FAILURE, $this->tester->getStatusCode());
        // Normalized: SymfonyStyle wraps the error block at the terminal width,
        // which splits multi-word phrases on narrow CI runners.
        $display = \preg_replace('/\s+/', ' ', $this->tester->getDisplay()) ?? '';
        self::assertStringContainsString('test.failing', $display);
        self::assertStringContainsString('rolled back', $display);
        self::assertStringContainsString('no changes were persisted', $display);

        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        self::assertFalse($storage->has('test.failing'), 'Rollback must leave no execution record.');
    }
}
