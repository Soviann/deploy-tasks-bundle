<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Real-server connections for the DBAL functional tests, from the MYSQL_DSN /
 * MARIADB_DSN / PGSQL_DSN environment variables (CI service containers).
 *
 * Parses with DsnParser: the `url` connection parameter is gone in DBAL 4.
 */
final class TestDsn
{
    private const SCHEME_TO_DRIVER = [
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'pgsql' => 'pdo_pgsql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
    ];

    /**
     * The DSN in $variable, or null when it is unset or empty.
     */
    public static function get(string $variable): ?string
    {
        $dsn = $_ENV[$variable] ?? $_SERVER[$variable] ?? \getenv($variable);

        return \is_string($dsn) && '' !== $dsn ? $dsn : null;
    }

    /**
     * @param array<string, mixed> $overrides Connection parameters taking precedence over the DSN's
     */
    public static function connect(string $dsn, array $overrides = []): Connection
    {
        /** @var array{driver: 'pdo_mysql'|'pdo_pgsql'} $params */
        $params = $overrides + (new DsnParser(self::SCHEME_TO_DRIVER))->parse($dsn);

        return DriverManager::getConnection($params);
    }
}
