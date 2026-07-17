<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use Soviann\DeployTasksBundle\Tests\Fixtures\SchemaManagingStorageFixture;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\KernelConfig;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;

final class CreateSchemaCommandRegistrationTest extends FunctionalTestCase
{
    public function testCommandIsRegisteredWithDbalStorage(): void
    {
        self::useConfigurableKernel(KernelConfig::dbalExtension(), KernelConfig::dbalServices());
        self::bootKernel();

        $app = new Application(self::kernel());
        self::assertTrue($app->has('deploytasks:create-schema'));
    }

    public function testCommandIsAbsentWithFilesystemStorage(): void
    {
        self::bootKernel();

        $app = new Application(self::kernel());
        self::assertFalse($app->has('deploytasks:create-schema'));
    }

    public function testCommandIsAbsentWithCustomStorage(): void
    {
        self::useConfigurableKernel(KernelConfig::customStorageExtension(), KernelConfig::customStorageServices());
        self::bootKernel();

        $app = new Application(self::kernel());
        self::assertFalse($app->has('deploytasks:create-schema'));
    }

    public function testCommandIsRegisteredWithCustomSchemaManageableStorage(): void
    {
        self::useSchemaManagingStorageKernel();
        self::bootKernel();

        $app = new Application(self::kernel());
        self::assertTrue($app->has('deploytasks:create-schema'));
    }

    public function testCommandProvisionsCustomSchemaManageableStorage(): void
    {
        self::useSchemaManagingStorageKernel();
        self::bootKernel();

        $tester = $this->runConsoleCommand('deploytasks:create-schema');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $storage = $this->storage();
        self::assertInstanceOf(SchemaManagingStorageFixture::class, $storage);
        self::assertTrue($storage->isSchemaCreated());

        // A custom backend gets the generic success message — the table/column/
        // connection details are DBAL-specific and unavailable here.
        $display = (string) \preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Storage schema was created.', $display);
        self::assertStringNotContainsString('was created on', $display);
    }

    public function testDumpSqlPrintsTheCustomBackendSql(): void
    {
        self::useSchemaManagingStorageKernel();
        self::bootKernel();

        $tester = $this->runConsoleCommand('deploytasks:create-schema', ['--dump-sql' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(
            SchemaManagingStorageFixture::CREATE_SQL.';',
            $tester->getDisplay(),
        );

        $storage = $this->storage();
        self::assertInstanceOf(SchemaManagingStorageFixture::class, $storage);
        self::assertFalse($storage->isSchemaCreated(), '--dump-sql must not execute the DDL.');
    }

    /**
     * Custom-storage scenario whose backend implements SchemaManageableInterface.
     * Used by this test class only, so it stays inline (see KernelConfig).
     */
    private static function useSchemaManagingStorageKernel(): void
    {
        self::useConfigurableKernel(
            [
                'storage' => [
                    'type' => 'custom',
                    'custom' => ['service' => 'test.schema_managing_storage'],
                ],
                'events' => ['enabled' => false],
                'lock' => ['enabled' => false],
            ],
            [
                'test.schema_managing_storage' => [
                    'class' => SchemaManagingStorageFixture::class,
                    'public' => true,
                ],
            ],
        );
    }
}
