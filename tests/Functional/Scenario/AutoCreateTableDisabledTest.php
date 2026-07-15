<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Tests\Fixtures\SimpleTask;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\KernelConfig;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class AutoCreateTableDisabledTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::useConfigurableKernel([
            'storage' => [
                'type' => 'database',
                'database' => [
                    'connection' => 'default',
                    'table' => 'deploy_task_executions',
                    'transaction_mode' => 'none',
                    'auto_create_table' => false,
                ],
            ],
            'events' => ['enabled' => false],
            'lock' => ['enabled' => false],
        ], [
            // Silence Symfony's default Logger: this scenario exercises the failure
            // path, which otherwise writes [critical] to stderr. Infection's
            // InitialTestsRunner kills PHPUnit on the first stderr byte.
            'logger' => ['class' => NullLogger::class, 'public' => true],
            'doctrine.dbal.default_connection' => KernelConfig::sqliteConnection(),
            'auto_create_table.task.simple' => [
                'class' => SimpleTask::class,
                'args' => ['auto_create_table.simple', 'Auto-create-table disabled fixture'],
                'tags' => ['soviann_deploy_tasks.task'],
            ],
        ]);
    }

    public function testRunFailsWithClearErrorWhenTableMissing(): void
    {
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($conn instanceof Connection);
        $schema = $conn->createSchemaManager();
        if ($schema->tablesExist(['deploy_task_executions'])) {
            $schema->dropTable('deploy_task_executions');
        }

        $app = new Application(self::bootKernel());
        $app->setAutoExit(false);
        $app->setCatchExceptions(true);

        $output = new BufferedOutput();
        $exit = $app->run(new ArrayInput(['command' => 'deploytasks:run']), $output);

        self::assertNotSame(0, $exit, 'run should fail when table is missing and auto_create_table is false');
        self::assertMatchesRegularExpression('/deploy_task_executions|table|doctrine/i', $output->fetch());
    }
}
