<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Storage\Dbal;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;

/**
 * Functional tests for DbalStorage covering cross-platform upsert semantics.
 *
 * The concurrency probe (testConcurrentUpsertSqlite) reproduces the DELETE+INSERT
 * race by manually interleaving raw SQL operations: it proves the race exists in
 * the old pattern and verifies that storage-level save() (the upsert path) handles
 * the same scenario without error.
 */
#[CoversClass(DbalStorage::class)]
final class DbalStorageTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.'dt_upsert_race_'.\bin2hex(\random_bytes(6)).'.db';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->dbFile)) {
            \unlink($this->dbFile);
        }

        // WAL mode creates sidecar files.
        foreach (['-wal', '-shm'] as $suffix) {
            $sidecar = $this->dbFile.$suffix;

            if (\file_exists($sidecar)) {
                \unlink($sidecar);
            }
        }
    }

    /**
     * Concurrency probe for SQLite: demonstrates that the DELETE+INSERT race exists
     * and that the platform-native upsert eliminates it.
     *
     * The old save() executed: BEGIN → DELETE WHERE (id, group) → INSERT → COMMIT.
     * Under concurrent writers, after W1's DELETE the row is gone; W2 wins the gap
     * and INSERTs the same key; W1's subsequent INSERT then collides with W2's
     * committed row, raising "UNIQUE constraint failed".
     *
     * This test reproduces that sequence manually using autocommit mode (no wrapping
     * transaction) so a second PDO connection can write in the gap without being blocked
     * by a write lock:
     *   1. Delete the seeded row (simulates W1's DELETE).
     *   2. A second PDO connection inserts a new row for the same key (simulates W2
     *      completing its save in the gap).
     *   3. The original connection tries to INSERT the same key — UNIQUE violation
     *      confirms the race is real.
     *   4. storage-level save() (upsert) is then called and must succeed.
     */
    public function testConcurrentUpsertSqlite(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbFile]);
        $config = new DbalStorageConfiguration(autoCreateTable: false);
        $storage = new DbalStorage($conn, $config);
        $conn->executeStatement($storage->getCreateTableSql());

        // Seed an initial row.
        $initial = new TaskExecution('task.race', TaskStatus::Ran, new \DateTimeImmutable('2026-04-28T10:00:00+00:00'));
        $storage->save($initial);

        self::assertCount(1, $storage->all(), 'Precondition: one row before race.');

        // Step 1: W1 deletes the row in autocommit mode (no wrapping transaction).
        $conn->executeStatement(
            'DELETE FROM "deploy_task_executions" WHERE "id" = ? AND "task_group" = ?',
            ['task.race', ''],
        );

        // Step 2: A second PDO connection inserts a fresh row for the same key while
        // W1's transaction is conceptually open — simulates W2 winning the race gap.
        $pdoB = new \PDO('sqlite:'.$this->dbFile);
        $stmt = $pdoB->prepare(
            'INSERT INTO "deploy_task_executions" ("id", "task_group", "status", "executed_at", "error") VALUES (?, ?, ?, ?, ?)',
        );
        $stmt->execute(['task.race', '', 'failed', '2026-04-28T10:00:01+00:00', 'race-write-B']);
        unset($stmt, $pdoB);

        // Step 3: W1 tries to INSERT — this collides with W2's row and raises
        // "UNIQUE constraint failed", proving the race is real.
        $oldPatternWouldFail = false;

        try {
            $conn->executeStatement(
                'INSERT INTO "deploy_task_executions" ("id", "task_group", "status", "executed_at", "error") VALUES (?, ?, ?, ?, ?)',
                ['task.race', '', 'ran', '2026-04-28T10:00:02+00:00', null],
            );
        } catch (\Throwable) {
            $oldPatternWouldFail = true;
        }

        self::assertTrue($oldPatternWouldFail, 'DELETE+INSERT race IS real: raw INSERT after a concurrent write collides on UNIQUE constraint.');

        // Step 4: storage-level save() uses the platform-native upsert and must
        // succeed even after the interleaved writes, leaving exactly one row.
        $recovery = new TaskExecution('task.race', TaskStatus::Ran, new \DateTimeImmutable('2026-04-28T10:00:03+00:00'));
        $storage->save($recovery);

        $all = $storage->all();

        self::assertCount(1, $all, 'Exactly one row must survive after upsert-based save().');
        self::assertSame(TaskStatus::Ran, $all[0]->status);
    }

    // -------------------------------------------------------------------------
    // Per-platform saveTwiceWithSameIdReplacesRow matrix
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: \Closure(): \Doctrine\DBAL\Connection, 1: class-string}>
     */
    public static function platformProvider(): iterable
    {
        yield 'SQLite' => [
            static fn (): \Doctrine\DBAL\Connection => DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            SQLitePlatform::class,
        ];

        if ('' !== ($_ENV['MYSQL_DSN'] ?? '')) {
            yield 'MySQL' => [
                static fn (): \Doctrine\DBAL\Connection => DriverManager::getConnection(['url' => $_ENV['MYSQL_DSN']]),
                MySQLPlatform::class,
            ];
        }

        if ('' !== ($_ENV['MARIADB_DSN'] ?? '')) {
            yield 'MariaDB' => [
                static fn (): \Doctrine\DBAL\Connection => DriverManager::getConnection(['url' => $_ENV['MARIADB_DSN']]),
                MariaDBPlatform::class,
            ];
        }

        if ('' !== ($_ENV['PGSQL_DSN'] ?? '')) {
            yield 'PostgreSQL' => [
                static fn (): \Doctrine\DBAL\Connection => DriverManager::getConnection(['url' => $_ENV['PGSQL_DSN']]),
                PostgreSQLPlatform::class,
            ];
        }
    }

    /**
     * Verifies that saving the same (id, group) twice via the platform-native upsert
     * results in exactly one row with the latest data (no UNIQUE constraint violation).
     *
     * @param \Closure(): \Doctrine\DBAL\Connection $makeConnection
     * @param class-string                          $expectedPlatform
     */
    #[DataProvider('platformProvider')]
    public function testSaveTwiceWithSameIdReplacesRow(
        \Closure $makeConnection,
        string $expectedPlatform,
    ): void {
        $connection = $makeConnection();
        $storage = new DbalStorage($connection);
        $connection->executeStatement($storage->getCreateTableSql());

        self::assertInstanceOf($expectedPlatform, $connection->getDatabasePlatform(), \sprintf(
            'Expected platform %s — check your DSN env variable.',
            $expectedPlatform,
        ));

        $first = new TaskExecution('task.upsert', TaskStatus::Ran, new \DateTimeImmutable('2026-04-28T10:00:00+00:00'));
        $second = new TaskExecution('task.upsert', TaskStatus::Failed, new \DateTimeImmutable('2026-04-28T10:01:00+00:00'), 'replaced');

        $storage->save($first);
        $storage->save($second);

        $all = $storage->all();

        self::assertCount(1, $all, 'Upsert must yield exactly one row for the (id, group) pair.');
        self::assertSame(TaskStatus::Failed, $all[0]->status, 'Second write must overwrite the first.');
        self::assertSame('replaced', $all[0]->error);
    }

    /**
     * Verifies that saving (id, group1) and (id, group2) yields two rows — the upsert
     * keyed on (id, task_group) must not clobber a different group's slot.
     */
    public function testUpsertScopedByGroup(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $storage = new DbalStorage($connection);
        $connection->executeStatement($storage->getCreateTableSql());

        $storage->save(new TaskExecution('task.g', TaskStatus::Ran, new \DateTimeImmutable(), null, 'groupA'));
        $storage->save(new TaskExecution('task.g', TaskStatus::Skipped, new \DateTimeImmutable(), null, 'groupB'));

        $all = $storage->all();
        self::assertCount(2, $all, 'Different group slots must coexist as separate rows.');

        $groups = \array_map(static fn (TaskExecution $e): ?string => $e->group, $all);
        \sort($groups);
        self::assertSame(['groupA', 'groupB'], $groups);
    }
}
