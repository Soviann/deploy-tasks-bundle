<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Storage\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\AllOrNothingFailureException;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator;
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
use Soviann\DeployTasksBundle\Runner\TransactionMode;
use Soviann\DeployTasksBundle\Sorting\DefaultTaskSorter;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;
use Soviann\DeployTasksBundle\Tests\Fixtures\ArrayLogger;
use Soviann\DeployTasksBundle\Tests\Fixtures\FourByteErrorTask;
use Soviann\DeployTasksBundle\Tests\Support\TestDsn;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Real-server proof that a `utf8` (utf8mb3) MySQL/MariaDB connection, which rejects
 * 4-byte UTF-8 characters with error 1366 in strict mode — whatever the column
 * charset — still gets the failure record stored, with those characters replaced
 * by U+FFFD.
 *
 * Runs against the servers named by MYSQL_DSN / MARIADB_DSN (CI service containers);
 * skipped when neither is set.
 */
#[CoversClass(DbalStorage::class)]
final class DbalStorageConnectionCharsetTest extends TestCase
{
    private const TABLE = 'deploy_task_charset_probe';

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        $this->connection?->executeStatement(\sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
        $this->connection?->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function serverProvider(): iterable
    {
        yield 'MySQL' => ['MYSQL_DSN'];
        yield 'MariaDB' => ['MARIADB_DSN'];
    }

    #[DataProvider('serverProvider')]
    public function testFailureRecordIsStoredWithFourByteCharactersReplaced(string $dsnVariable): void
    {
        $storage = $this->utf8mb3Storage($dsnVariable, $logger = new ArrayLogger());

        $storage->save(new TaskExecution(
            'task.emoji', TaskStatus::Failed, new \DateTimeImmutable('2026-11-24T10:00:00+00:00'), FourByteErrorTask::MESSAGE,
        ));

        $stored = $storage->get('task.emoji');

        self::assertNotNull($stored);
        self::assertSame(TaskStatus::Failed, $stored->status);
        self::assertSame("Payment provider down \u{FFFD} (caf\u{E9} \u{FFFD})", $stored->error);
        self::assertTrue($logger->has('warning', 'U+FFFD'));
    }

    /**
     * End to end through the runner (per_task): the task stays pending with a
     * FAILED record instead of losing the record to a save failure.
     */
    #[DataProvider('serverProvider')]
    public function testRunnerRecordsTheFailureOfATaskWithFourByteCharactersInItsError(string $dsnVariable): void
    {
        $storage = $this->utf8mb3Storage($dsnVariable);

        $result = $this->runner($storage, TransactionMode::PerTask)->runAll(new BufferedOutput());

        self::assertFalse($result->isSuccessful());
        $stored = $storage->get('test.four_byte_error');
        self::assertNotNull($stored, 'The failure must be recorded despite the utf8mb3 connection.');
        self::assertSame(TaskStatus::Failed, $stored->status);
        self::assertSame("Payment provider down \u{FFFD} (caf\u{E9} \u{FFFD})", $stored->error);
    }

    /**
     * all_or_nothing (the database storage's default) rolls the failure record back
     * with the run by design; what must surface is the task's own error, not the
     * failure to save its record.
     */
    #[DataProvider('serverProvider')]
    public function testAllOrNothingRunSurfacesTheTaskErrorRatherThanASaveFailure(string $dsnVariable): void
    {
        $storage = $this->utf8mb3Storage($dsnVariable);

        try {
            $this->runner($storage, TransactionMode::AllOrNothing)->runAll(new BufferedOutput());
            self::fail('Expected AllOrNothingFailureException');
        } catch (AllOrNothingFailureException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(\RuntimeException::class, $previous);
            self::assertNotInstanceOf(StorageException::class, $previous);
            self::assertSame(FourByteErrorTask::MESSAGE, $previous->getMessage());
        }

        self::assertNull($storage->get('test.four_byte_error'), 'Rolled back with the run: the task stays pending.');
    }

    private function runner(DbalStorage $storage, TransactionMode $mode): TaskRunner
    {
        $idResolver = new TaskIdResolver(new DefaultTaskIdGenerator());

        return new TaskRunner(
            new TaskRegistry([new FourByteErrorTask()], $idResolver),
            $storage,
            new DefaultTaskSorter($idResolver),
            $idResolver,
            new TaskDescriptionResolver(),
            300,
            $mode,
            300,
        );
    }

    private function utf8mb3Storage(string $dsnVariable, ?ArrayLogger $logger = null): DbalStorage
    {
        $dsn = TestDsn::get($dsnVariable);

        if (null === $dsn) {
            self::markTestSkipped(\sprintf('%s is not set.', $dsnVariable));
        }

        // The connection charset under test: 3-byte utf8.
        $this->connection = TestDsn::connect($dsn, ['charset' => 'utf8']);
        self::assertInstanceOf(AbstractMySQLPlatform::class, $this->connection->getDatabasePlatform());
        // SET NAMES pins the whole connection to utf8mb3: a server-side init_connect
        // forcing collation_connection back to utf8mb4 (as some dev images do) would
        // otherwise turn the rejection into a silent '?' substitution.
        $this->connection->executeStatement('SET NAMES utf8mb3');
        // Strict mode (the MySQL 5.7+ / MariaDB 10.2.4+ default) turns the charset
        // mismatch into error 1366; non-strict servers store '?' with a warning instead.
        $this->connection->executeStatement("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_TRANS_TABLES')");
        $this->connection->executeStatement(\sprintf('DROP TABLE IF EXISTS %s', self::TABLE));

        $storage = new DbalStorage($this->connection, new DbalStorageConfiguration(tableName: self::TABLE), $logger);
        $storage->createSchema();

        return $storage;
    }
}
