<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;

/**
 * Pins the storage table DDL, platform by platform, to the reference emitted by the
 * DBAL 4.3+ schema editors, so DBAL 3 and DBAL 4 applications get the same table
 * (no `utf8` charset / ENGINE injected by DBAL 3's MySQL platforms, no DC2Type
 * comments) and upgrading DBAL needs no schema change.
 *
 * Both builders are checked against the same reference on every DBAL line: on 4.3+
 * getCreateTableSql() runs the editors and the legacy builder is invoked directly;
 * on DBAL 3 both run the legacy builder. Whitespace is ignored — DBAL 3 and 4 only
 * differ in spacing (`PRIMARY KEY(` vs `PRIMARY KEY (`), which is not schema.
 */
#[CoversClass(DbalStorage::class)]
final class DbalSchemaParityTest extends TestCase
{
    /**
     * @return iterable<string, array{(\Closure(): AbstractPlatform)|null, DbalStorageConfiguration, string}>
     */
    public static function platformAndConfigurationProvider(): iterable
    {
        $keywordConfiguration = new DbalStorageConfiguration(
            tableName: 'order',
            idColumn: 'default',
            idColumnLength: 191,
            groupColumn: 'group',
            groupColumnLength: 64,
            statusColumn: 'select',
            executedAtColumn: 'when',
            errorColumn: 'from',
            durationColumn: 'limit',
        );

        // null = a real in-memory SQLite connection (its platform class name differs
        // between DBAL 3 and 4, so it is not instantiated directly).
        yield 'sqlite / defaults' => [null, new DbalStorageConfiguration(),
            'CREATE TABLE "deploy_task_executions" ("id" VARCHAR(255) NOT NULL, "task_group" VARCHAR(128) DEFAULT \'\' NOT NULL, "status" VARCHAR(16) NOT NULL, "executed_at" DATETIME NOT NULL, "error" CLOB DEFAULT NULL, "duration_ms" INTEGER DEFAULT NULL, PRIMARY KEY ("id", "task_group"))'];
        yield 'sqlite / keyword-shaped names' => [null, $keywordConfiguration,
            'CREATE TABLE "order" ("default" VARCHAR(191) NOT NULL, "group" VARCHAR(64) DEFAULT \'\' NOT NULL, "select" VARCHAR(16) NOT NULL, "when" DATETIME NOT NULL, "from" CLOB DEFAULT NULL, "limit" INTEGER DEFAULT NULL, PRIMARY KEY ("default", "group"))'];

        foreach (['mysql' => static fn (): AbstractPlatform => new MySQLPlatform(), 'mariadb' => static fn (): AbstractPlatform => new MariaDBPlatform()] as $name => $factory) {
            yield $name.' / defaults' => [$factory, new DbalStorageConfiguration(),
                'CREATE TABLE `deploy_task_executions` (`id` VARCHAR(255) NOT NULL, `task_group` VARCHAR(128) DEFAULT \'\' NOT NULL, `status` VARCHAR(16) NOT NULL, `executed_at` DATETIME NOT NULL, `error` LONGTEXT DEFAULT NULL, `duration_ms` INT DEFAULT NULL, PRIMARY KEY (`id`, `task_group`))'];
            yield $name.' / keyword-shaped names' => [$factory, $keywordConfiguration,
                'CREATE TABLE `order` (`default` VARCHAR(191) NOT NULL, `group` VARCHAR(64) DEFAULT \'\' NOT NULL, `select` VARCHAR(16) NOT NULL, `when` DATETIME NOT NULL, `from` LONGTEXT DEFAULT NULL, `limit` INT DEFAULT NULL, PRIMARY KEY (`default`, `group`))'];
        }

        $postgresql = static fn (): AbstractPlatform => new PostgreSQLPlatform();
        yield 'postgresql / defaults' => [$postgresql, new DbalStorageConfiguration(),
            'CREATE TABLE "deploy_task_executions" ("id" VARCHAR(255) NOT NULL, "task_group" VARCHAR(128) DEFAULT \'\' NOT NULL, "status" VARCHAR(16) NOT NULL, "executed_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, "error" TEXT DEFAULT NULL, "duration_ms" INT DEFAULT NULL, PRIMARY KEY ("id", "task_group"))'];
        yield 'postgresql / keyword-shaped names' => [$postgresql, $keywordConfiguration,
            'CREATE TABLE "order" ("default" VARCHAR(191) NOT NULL, "group" VARCHAR(64) DEFAULT \'\' NOT NULL, "select" VARCHAR(16) NOT NULL, "when" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, "from" TEXT DEFAULT NULL, "limit" INT DEFAULT NULL, PRIMARY KEY ("default", "group"))'];
    }

    /**
     * @param (\Closure(): AbstractPlatform)|null $platformFactory
     */
    #[DataProvider('platformAndConfigurationProvider')]
    public function testInstalledDbalPathEmitsTheReferenceDdl(?\Closure $platformFactory, DbalStorageConfiguration $configuration, string $expectedDdl): void
    {
        $storage = new DbalStorage($this->connection($platformFactory), $configuration);

        self::assertSame(self::normalize($expectedDdl), self::normalize($storage->getCreateTableSql()));
    }

    /**
     * @param (\Closure(): AbstractPlatform)|null $platformFactory
     */
    #[DataProvider('platformAndConfigurationProvider')]
    public function testLegacySchemaPathEmitsTheReferenceDdl(?\Closure $platformFactory, DbalStorageConfiguration $configuration, string $expectedDdl): void
    {
        $storage = new DbalStorage($this->connection($platformFactory), $configuration);

        /** @var list<string> $legacySql */
        $legacySql = (new \ReflectionMethod(DbalStorage::class, 'buildLegacySchemaSql'))->invoke($storage);

        self::assertSame(self::normalize($expectedDdl), self::normalize(\implode(";\n", $legacySql)));
    }

    private static function normalize(string $sql): string
    {
        return (string) \preg_replace('/\s+/', '', $sql);
    }

    /**
     * @param (\Closure(): AbstractPlatform)|null $platformFactory
     */
    private function connection(?\Closure $platformFactory): Connection
    {
        if (null === $platformFactory) {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platformFactory());

        return $connection;
    }
}
