<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Helper;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Helper\HostRunnerConfig;

#[CoversClass(HostRunnerConfig::class)]
final class HostRunnerConfigTest extends TestCase
{
    public function testPathUnderProjectDirBecomesProjectRelative(): void
    {
        self::assertSame(
            'deploy/host-tasks',
            HostRunnerConfig::runnerPath('/app/deploy/host-tasks', '/app'),
        );
    }

    public function testPathOutsideProjectDirStaysAbsolute(): void
    {
        self::assertSame(
            '/srv/shared/host-tasks',
            HostRunnerConfig::runnerPath('/srv/shared/host-tasks', '/app'),
        );
    }

    public function testEnvValuesDerivesFromEnvVarsInOrder(): void
    {
        self::assertSame(
            [
                'DEPLOY_TASKS_HOST_DIR' => 'deploy/host-tasks',
                'DEPLOY_TASKS_HOST_STORAGE' => 'deploy/host-tasks.log',
                'DEPLOY_TASKS_HOST_LOCK' => '/srv/shared/host-tasks.lock',
            ],
            HostRunnerConfig::envValues(
                '/app/deploy/host-tasks',
                '/app/deploy/host-tasks.log',
                '/srv/shared/host-tasks.lock',
                '/app',
            ),
        );
    }

    public function testReadGeneratedLocalShIgnoresHandWrittenFiles(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'dt-localsh-');
        self::assertNotFalse($path);
        \file_put_contents($path, "export DEPLOY_TASKS_HOST_DIR='x'\n");

        self::assertNull(HostRunnerConfig::readGeneratedLocalSh($path));

        \unlink($path);
    }

    public function testReadGeneratedLocalShParsesGeneratedExports(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'dt-localsh-');
        self::assertNotFalse($path);
        \file_put_contents($path, HostRunnerConfig::GENERATED_MARKER." — regenerate after changing soviann_deploy_tasks.host.*\nexport DEPLOY_TASKS_HOST_DIR='deploy/host-tasks'\nexport DEPLOY_TASKS_HOST_STORAGE='.deploy-tasks-host.log'\nexport DEPLOY_TASKS_HOST_LOCK='.deploy-tasks-host.lock'\n");

        self::assertSame(
            [
                'DEPLOY_TASKS_HOST_DIR' => 'deploy/host-tasks',
                'DEPLOY_TASKS_HOST_STORAGE' => '.deploy-tasks-host.log',
                'DEPLOY_TASKS_HOST_LOCK' => '.deploy-tasks-host.lock',
            ],
            HostRunnerConfig::readGeneratedLocalSh($path),
        );

        \unlink($path);
    }

    public function testReadGeneratedLocalShParsesCrlfLineEndings(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'dt-localsh-');
        self::assertNotFalse($path);
        \file_put_contents($path, \str_replace(
            "\n",
            "\r\n",
            HostRunnerConfig::GENERATED_MARKER." — regenerate after changing soviann_deploy_tasks.host.*\nexport DEPLOY_TASKS_HOST_DIR='deploy/host-tasks'\nexport DEPLOY_TASKS_HOST_STORAGE='.deploy-tasks-host.log'\nexport DEPLOY_TASKS_HOST_LOCK='.deploy-tasks-host.lock'\n",
        ));

        self::assertSame(
            [
                'DEPLOY_TASKS_HOST_DIR' => 'deploy/host-tasks',
                'DEPLOY_TASKS_HOST_STORAGE' => '.deploy-tasks-host.log',
                'DEPLOY_TASKS_HOST_LOCK' => '.deploy-tasks-host.lock',
            ],
            HostRunnerConfig::readGeneratedLocalSh($path),
        );

        \unlink($path);
    }
}
