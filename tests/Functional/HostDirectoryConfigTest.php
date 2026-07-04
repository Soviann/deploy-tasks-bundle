<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateHostCommand;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Soviann\DeployTasksBundle\Tests\Support\HostTasksKernelFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Verifies that soviann_deploy_tasks.host.directory is honoured at kernel-boot time
 * and that the default value (%kernel.project_dir%/deploy/host-tasks) is resolved correctly.
 */
#[CoversClass(SoviannDeployTasksBundle::class)]
#[CoversClass(DeployTasksGenerateHostCommand::class)]
final class HostDirectoryConfigTest extends KernelTestCase
{
    /** @var string Project dir used as the fake root for both test cases */
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = FilesystemTestHelper::tempDir('deploy-tasks-host-dir-cfg-');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        FilesystemTestHelper::cleanup($this->projectDir);
        HostTasksKernelFactory::cleanupAll();
        \restore_exception_handler();
    }

    public function testConfiguredHostDirectoryIsUsed(): void
    {
        // A sub-directory inside the fake project root so the path-traversal guard is satisfied.
        $expectedAbsolute = $this->projectDir.'/var/test-host-tasks';

        $kernel = HostTasksKernelFactory::boot($this->projectDir, $expectedAbsolute);
        $tester = new CommandTester((new Application($kernel))->find('deploytasks:generate:host'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $files = \glob($expectedAbsolute.'/deploy_task_*.sh');
        self::assertNotFalse($files);
        self::assertCount(1, $files, 'Expected exactly one generated stub in the configured host.directory.');

        $kernel->shutdown();
    }

    public function testDefaultHostDirectoryIsResolvedFromKernelProjectDir(): void
    {
        $kernel = HostTasksKernelFactory::boot($this->projectDir);
        $tester = new CommandTester((new Application($kernel))->find('deploytasks:generate:host'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        // The default config value '%kernel.project_dir%/deploy/host-tasks' must resolve
        // to the fake project dir — NOT to the bundle's own directory.
        $expectedDir = $this->projectDir.'/deploy/host-tasks';
        $files = \glob($expectedDir.'/deploy_task_*.sh');
        self::assertNotFalse($files);
        self::assertCount(
            1,
            $files,
            'Expected exactly one stub in the default deploy/host-tasks directory under project dir.',
        );

        $kernel->shutdown();
    }

    public function testConfiguredHostDirectoryOutsideProjectDirIsAccepted(): void
    {
        // Legitimate per the DEPLOY_TASKS_HOST_DIR contract; status/skip:host/
        // reset:host/rollup:host already accept it — generate:host must too.
        $outsideDir = FilesystemTestHelper::tempDir('deploy-tasks-outside-host-');

        try {
            $kernel = HostTasksKernelFactory::boot($this->projectDir, $outsideDir);
            $tester = new CommandTester((new Application($kernel))->find('deploytasks:generate:host'));
            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
            $files = \glob($outsideDir.'/deploy_task_*.sh');
            self::assertNotFalse($files);
            self::assertCount(1, $files);
            $kernel->shutdown();
        } finally {
            FilesystemTestHelper::cleanup($outsideDir);
        }
    }
}
