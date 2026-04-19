<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use Doctrine\DBAL\Connection;
use Soviann\DeployTasksBundle\Tests\Functional\AutoCreateTableDisabledKernel;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class AutoCreateTableDisabledTest extends FunctionalTestCase
{
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

    protected static function getKernelClass(): string
    {
        return AutoCreateTableDisabledKernel::class;
    }
}
