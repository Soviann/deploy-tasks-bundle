<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksRollupCommand;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\KernelConfig;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The shared TestKernel always registers fixture tasks, so the empty-registry
 * branch needs its own kernel config: the canonical custom-storage scenario
 * with no task services at all.
 */
#[CoversClass(DeployTasksRollupCommand::class)]
final class DeployRollupEmptyRegistryTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::useConfigurableKernel(KernelConfig::customStorageExtension(), KernelConfig::customStorageServices());
        self::bootKernel();
        $this->cleanStorage();
    }

    public function testRollupWithEmptyRegistryWarnsAndLeavesStorageUntouched(): void
    {
        $tester = new CommandTester((new Application(self::kernel()))->find('deploytasks:rollup'));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No tasks registered.', $tester->getDisplay());
        // Nothing registered means nothing to mark as run.
        self::assertSame([], $this->storage()->all());
    }
}
