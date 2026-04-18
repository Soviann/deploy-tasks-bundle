<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario\Kernel;

final class FilesystemLifecycleScenarioKernel extends AbstractLifecycleScenarioKernel
{
    protected static function kernelName(): string
    {
        return 'scenario-filesystem';
    }

    protected function storageConfig(): array
    {
        return [
            'type' => 'filesystem',
            'filesystem' => [
                'path' => \sys_get_temp_dir().'/deploy-tasks-scenario-filesystem-'.\getmypid(),
            ],
        ];
    }
}
