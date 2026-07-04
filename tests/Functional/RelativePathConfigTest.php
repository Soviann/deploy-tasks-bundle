<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Relative host.* and storage.filesystem.path values must resolve against
 * kernel.project_dir, not the process CWD — a console command launched from
 * cron (CWD=/) must read and write the same files as one launched from the
 * project root.
 */
#[CoversClass(SoviannDeployTasksBundle::class)]
final class RelativePathConfigTest extends FunctionalTestCase
{
    private string $originalCwd;

    protected function setUp(): void
    {
        $cwd = \getcwd();
        self::assertNotFalse($cwd);
        $this->originalCwd = $cwd;
    }

    protected function tearDown(): void
    {
        \chdir($this->originalCwd);
        (new Filesystem())->remove([
            self::projectDir().'/var/rel-deploy-state',
            self::projectDir().'/var/rel-host-tasks',
        ]);
        parent::tearDown();
    }

    public function testRelativeFilesystemStoragePathResolvesAgainstProjectDir(): void
    {
        self::useConfigurableKernel([
            'storage' => ['type' => 'filesystem', 'filesystem' => ['path' => 'var/rel-deploy-state']],
        ]);
        self::bootKernel();

        \chdir(\sys_get_temp_dir());

        $this->storage()->save(new TaskExecution('t1', TaskStatus::Ran, new \DateTimeImmutable(), null, null));

        self::assertFileExists(self::projectDir().'/var/rel-deploy-state/t1.json');
        self::assertFileDoesNotExist(\sys_get_temp_dir().'/var/rel-deploy-state/t1.json');
    }

    public function testRelativeHostDirectoryResolvesAgainstProjectDir(): void
    {
        self::useConfigurableKernel([
            'host' => ['directory' => 'var/rel-host-tasks'],
        ]);
        self::bootKernel();

        $hostDir = self::projectDir().'/var/rel-host-tasks';
        (new Filesystem())->dumpFile($hostDir.'/deploy_task_20260101_000000.sh', "#!/usr/bin/env bash\n");

        \chdir(\sys_get_temp_dir());

        $tester = $this->runCommand('deploytasks:status');

        self::assertStringContainsString('Host tasks', $tester->getDisplay());
        self::assertStringContainsString('deploy_task_20260101_000000', $tester->getDisplay());
    }
}
