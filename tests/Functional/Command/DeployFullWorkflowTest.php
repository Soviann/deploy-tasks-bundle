<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DeployFullWorkflowTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->cleanStorage();
    }

    public function testFullLifecycleRunSkipResetForceRun(): void
    {
        $application = new Application(self::kernel());
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        $runner = new CommandTester($application->find('deploytasks:run'));
        $status = new CommandTester($application->find('deploytasks:status'));
        $skipper = new CommandTester($application->find('deploytasks:skip'));
        $resetter = new CommandTester($application->find('deploytasks:reset'));

        // 1. Run all default-slot tasks
        $runner->execute([]);
        self::assertSame(Command::SUCCESS, $runner->getStatusCode());
        self::assertStringContainsString('ran', $runner->getDisplay());

        // 2. Status shows test.simple as ran
        $status->execute([]);
        self::assertSame(Command::SUCCESS, $status->getStatusCode());
        self::assertStringContainsString('ran', $status->getDisplay());
        self::assertSame(TaskStatus::Ran, $storage->get('test.simple')?->status);

        // 3. Run predeploy group so we have a slot to skip/reset
        $runner->execute(['--group' => ['predeploy']]);
        self::assertSame(Command::SUCCESS, $runner->getStatusCode());
        $ran = $storage->get('test.predeploy', 'predeploy');
        \assert(null !== $ran);
        self::assertSame(TaskStatus::Ran, $ran->status);

        // 4. Skip the predeploy slot
        $skipper->execute(['id' => 'test.predeploy', '--group' => 'predeploy', '--no-interaction' => true]);
        self::assertSame(Command::SUCCESS, $skipper->getStatusCode());
        $skipped = $storage->get('test.predeploy', 'predeploy');
        \assert(null !== $skipped);
        self::assertSame(TaskStatus::Skipped, $skipped->status);

        // 5. Reset the predeploy slot back to pending
        $resetter->execute(['id' => 'test.predeploy', '--group' => 'predeploy', '--no-interaction' => true]);
        self::assertSame(Command::SUCCESS, $resetter->getStatusCode());
        self::assertNull($storage->get('test.predeploy', 'predeploy'));

        // 6. Re-run the predeploy group again
        $runner->execute(['--group' => ['predeploy'], '--rerun-all' => true]);
        self::assertSame(Command::SUCCESS, $runner->getStatusCode());
        self::assertStringContainsString('ran', $runner->getDisplay());
        // PHPStan narrowed this call to null from the assertNull at step 5; the runner
        // command mutates storage between then and now, which PHPStan can't observe.
        /** @var ?TaskExecution $reRan */
        $reRan = $storage->get('test.predeploy', 'predeploy');
        \assert(null !== $reRan);
        self::assertSame(TaskStatus::Ran, $reRan->status);
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }
}
