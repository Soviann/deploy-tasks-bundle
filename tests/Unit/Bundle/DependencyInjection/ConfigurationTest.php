<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Bundle\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\EventsConfigNode;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\LockConfigNode;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\StorageConfigNode;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;

/**
 * Round-trip snapshot guard for the composed configuration tree.
 *
 * Freezes the processed-config output of every known YAML shape so any future
 * edit to `SoviannDeployTasksBundle::configure()` or its per-subtree nodes that shifts
 * a default, renames a key, or drops a field fails here first.
 */
#[CoversClass(SoviannDeployTasksBundle::class)]
#[CoversClass(StorageConfigNode::class)]
#[CoversClass(EventsConfigNode::class)]
#[CoversClass(LockConfigNode::class)]
final class ConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('configSnapshotProvider')]
    public function testProcessedConfigMatchesFrozenSnapshot(array $input, array $expected): void
    {
        self::assertSame($expected, self::processConfig($input));
    }

    /**
     * @return iterable<string, array{input: array<string, mixed>, expected: array<string, mixed>}>
     */
    public static function configSnapshotProvider(): iterable
    {
        yield 'empty input yields full default tree' => [
            'input' => [],
            'expected' => [
                'id_generator' => null,
                'sorter' => null,
                'logger' => null,
                'default_timeout' => 300,
                'storage' => [
                    'type' => 'filesystem',
                    'filesystem' => [
                        'path' => '%kernel.project_dir%/var/deploy-tasks',
                    ],
                    'database' => [
                        'connection' => 'default',
                        'table' => 'deploy_task_executions',
                        'auto_create_table' => true,
                        'id_column' => 'id',
                        'id_column_length' => 255,
                        'status_column' => 'status',
                        'executed_at_column' => 'executed_at',
                        'error_column' => 'error',
                        'group_column' => 'task_group',
                        'group_column_length' => 128,
                        'transaction_mode' => 'all_or_nothing',
                    ],
                    'custom' => [
                        'service' => null,
                        'transaction_mode' => 'none',
                    ],
                ],
                'events' => ['enabled' => true],
                'lock' => ['enabled' => true, 'ttl' => 3600],
                'generate' => [
                    'directory' => 'src/DeployTasks/Task/',
                    'template' => null,
                    'root_namespace' => 'App',
                ],
                'host' => [
                    'directory' => '%kernel.project_dir%/deploy/host-tasks',
                    'log_path' => '%kernel.project_dir%/.deploy-tasks-host.log',
                    'lock_path' => '%kernel.project_dir%/.deploy-tasks-host.lock',
                ],
            ],
        ];

        yield 'database storage type keeps every default' => [
            'input' => ['storage' => ['type' => 'database']],
            'expected' => [
                'storage' => [
                    'type' => 'database',
                    'filesystem' => [
                        'path' => '%kernel.project_dir%/var/deploy-tasks',
                    ],
                    'database' => [
                        'connection' => 'default',
                        'table' => 'deploy_task_executions',
                        'auto_create_table' => true,
                        'id_column' => 'id',
                        'id_column_length' => 255,
                        'status_column' => 'status',
                        'executed_at_column' => 'executed_at',
                        'error_column' => 'error',
                        'group_column' => 'task_group',
                        'group_column_length' => 128,
                        'transaction_mode' => 'all_or_nothing',
                    ],
                    'custom' => [
                        'service' => null,
                        'transaction_mode' => 'none',
                    ],
                ],
                'id_generator' => null,
                'sorter' => null,
                'logger' => null,
                'default_timeout' => 300,
                'events' => ['enabled' => true],
                'lock' => ['enabled' => true, 'ttl' => 3600],
                'generate' => [
                    'directory' => 'src/DeployTasks/Task/',
                    'template' => null,
                    'root_namespace' => 'App',
                ],
                'host' => [
                    'directory' => '%kernel.project_dir%/deploy/host-tasks',
                    'log_path' => '%kernel.project_dir%/.deploy-tasks-host.log',
                    'lock_path' => '%kernel.project_dir%/.deploy-tasks-host.lock',
                ],
            ],
        ];

        yield 'custom storage with service id' => [
            'input' => [
                'storage' => [
                    'type' => 'custom',
                    'custom' => ['service' => 'app.my_storage'],
                ],
            ],
            'expected' => [
                'storage' => [
                    'type' => 'custom',
                    'custom' => [
                        'service' => 'app.my_storage',
                        'transaction_mode' => 'none',
                    ],
                    'filesystem' => [
                        'path' => '%kernel.project_dir%/var/deploy-tasks',
                    ],
                    'database' => [
                        'connection' => 'default',
                        'table' => 'deploy_task_executions',
                        'auto_create_table' => true,
                        'id_column' => 'id',
                        'id_column_length' => 255,
                        'status_column' => 'status',
                        'executed_at_column' => 'executed_at',
                        'error_column' => 'error',
                        'group_column' => 'task_group',
                        'group_column_length' => 128,
                        'transaction_mode' => 'all_or_nothing',
                    ],
                ],
                'id_generator' => null,
                'sorter' => null,
                'logger' => null,
                'default_timeout' => 300,
                'events' => ['enabled' => true],
                'lock' => ['enabled' => true, 'ttl' => 3600],
                'generate' => [
                    'directory' => 'src/DeployTasks/Task/',
                    'template' => null,
                    'root_namespace' => 'App',
                ],
                'host' => [
                    'directory' => '%kernel.project_dir%/deploy/host-tasks',
                    'log_path' => '%kernel.project_dir%/.deploy-tasks-host.log',
                    'lock_path' => '%kernel.project_dir%/.deploy-tasks-host.lock',
                ],
            ],
        ];

        yield 'every field overridden round-trips unchanged' => [
            'input' => [
                'id_generator' => 'app.id',
                'sorter' => 'app.sorter',
                'logger' => 'app.logger',
                'default_timeout' => 600,
                'storage' => [
                    'type' => 'database',
                    'filesystem' => ['path' => '/custom/fs'],
                    'database' => [
                        'connection' => 'audit',
                        'table' => 't',
                        'auto_create_table' => false,
                        'id_column' => 'i',
                        'id_column_length' => 64,
                        'status_column' => 's',
                        'executed_at_column' => 'e',
                        'error_column' => 'er',
                        'group_column' => 'grp',
                        'group_column_length' => 64,
                        'transaction_mode' => 'none',
                    ],
                    'custom' => ['service' => 'c', 'transaction_mode' => 'all_or_nothing'],
                ],
                'events' => ['enabled' => false],
                'lock' => ['enabled' => false, 'ttl' => 1800],
                'generate' => ['directory' => 'src/T/', 'template' => '/tpl.php'],
                'host' => [
                    'directory' => '/srv/host-tasks',
                    'log_path' => '/srv/state/host.log',
                    'lock_path' => '/srv/state/host.lock',
                ],
            ],
            'expected' => [
                'id_generator' => 'app.id',
                'sorter' => 'app.sorter',
                'logger' => 'app.logger',
                'default_timeout' => 600,
                'storage' => [
                    'type' => 'database',
                    'filesystem' => [
                        'path' => '/custom/fs',
                    ],
                    'database' => [
                        'connection' => 'audit',
                        'table' => 't',
                        'auto_create_table' => false,
                        'id_column' => 'i',
                        'id_column_length' => 64,
                        'status_column' => 's',
                        'executed_at_column' => 'e',
                        'error_column' => 'er',
                        'group_column' => 'grp',
                        'group_column_length' => 64,
                        'transaction_mode' => 'none',
                    ],
                    'custom' => [
                        'service' => 'c',
                        'transaction_mode' => 'all_or_nothing',
                    ],
                ],
                'events' => ['enabled' => false],
                'lock' => ['enabled' => false, 'ttl' => 1800],
                'generate' => [
                    'directory' => 'src/T/',
                    'template' => '/tpl.php',
                    'root_namespace' => 'App',
                ],
                'host' => [
                    'directory' => '/srv/host-tasks',
                    'log_path' => '/srv/state/host.log',
                    'lock_path' => '/srv/state/host.lock',
                ],
            ],
        ];

        yield 'storage scalar shorthand expands to full type subtree' => [
            'input' => ['storage' => 'database'],
            'expected' => [
                'storage' => [
                    'type' => 'database',
                    'filesystem' => [
                        'path' => '%kernel.project_dir%/var/deploy-tasks',
                    ],
                    'database' => [
                        'connection' => 'default',
                        'table' => 'deploy_task_executions',
                        'auto_create_table' => true,
                        'id_column' => 'id',
                        'id_column_length' => 255,
                        'status_column' => 'status',
                        'executed_at_column' => 'executed_at',
                        'error_column' => 'error',
                        'group_column' => 'task_group',
                        'group_column_length' => 128,
                        'transaction_mode' => 'all_or_nothing',
                    ],
                    'custom' => [
                        'service' => null,
                        'transaction_mode' => 'none',
                    ],
                ],
                'id_generator' => null,
                'sorter' => null,
                'logger' => null,
                'default_timeout' => 300,
                'events' => ['enabled' => true],
                'lock' => ['enabled' => true, 'ttl' => 3600],
                'generate' => [
                    'directory' => 'src/DeployTasks/Task/',
                    'template' => null,
                    'root_namespace' => 'App',
                ],
                'host' => [
                    'directory' => '%kernel.project_dir%/deploy/host-tasks',
                    'log_path' => '%kernel.project_dir%/.deploy-tasks-host.log',
                    'lock_path' => '%kernel.project_dir%/.deploy-tasks-host.lock',
                ],
            ],
        ];

        yield 'events scalar false shorthand disables events' => [
            'input' => ['events' => false],
            'expected' => [
                'events' => ['enabled' => false],
                'id_generator' => null,
                'sorter' => null,
                'logger' => null,
                'default_timeout' => 300,
                'storage' => [
                    'type' => 'filesystem',
                    'filesystem' => [
                        'path' => '%kernel.project_dir%/var/deploy-tasks',
                    ],
                    'database' => [
                        'connection' => 'default',
                        'table' => 'deploy_task_executions',
                        'auto_create_table' => true,
                        'id_column' => 'id',
                        'id_column_length' => 255,
                        'status_column' => 'status',
                        'executed_at_column' => 'executed_at',
                        'error_column' => 'error',
                        'group_column' => 'task_group',
                        'group_column_length' => 128,
                        'transaction_mode' => 'all_or_nothing',
                    ],
                    'custom' => [
                        'service' => null,
                        'transaction_mode' => 'none',
                    ],
                ],
                'lock' => ['enabled' => true, 'ttl' => 3600],
                'generate' => [
                    'directory' => 'src/DeployTasks/Task/',
                    'template' => null,
                    'root_namespace' => 'App',
                ],
                'host' => [
                    'directory' => '%kernel.project_dir%/deploy/host-tasks',
                    'log_path' => '%kernel.project_dir%/.deploy-tasks-host.log',
                    'lock_path' => '%kernel.project_dir%/.deploy-tasks-host.lock',
                ],
            ],
        ];

        yield 'lock scalar false shorthand disables locking' => [
            'input' => ['lock' => false],
            'expected' => [
                'lock' => ['enabled' => false, 'ttl' => 3600],
                'id_generator' => null,
                'sorter' => null,
                'logger' => null,
                'default_timeout' => 300,
                'storage' => [
                    'type' => 'filesystem',
                    'filesystem' => [
                        'path' => '%kernel.project_dir%/var/deploy-tasks',
                    ],
                    'database' => [
                        'connection' => 'default',
                        'table' => 'deploy_task_executions',
                        'auto_create_table' => true,
                        'id_column' => 'id',
                        'id_column_length' => 255,
                        'status_column' => 'status',
                        'executed_at_column' => 'executed_at',
                        'error_column' => 'error',
                        'group_column' => 'task_group',
                        'group_column_length' => 128,
                        'transaction_mode' => 'all_or_nothing',
                    ],
                    'custom' => [
                        'service' => null,
                        'transaction_mode' => 'none',
                    ],
                ],
                'events' => ['enabled' => true],
                'generate' => [
                    'directory' => 'src/DeployTasks/Task/',
                    'template' => null,
                    'root_namespace' => 'App',
                ],
                'host' => [
                    'directory' => '%kernel.project_dir%/deploy/host-tasks',
                    'log_path' => '%kernel.project_dir%/.deploy-tasks-host.log',
                    'lock_path' => '%kernel.project_dir%/.deploy-tasks-host.lock',
                ],
            ],
        ];

        yield 'non-default group_column and group_column_length round-trip unchanged' => [
            'input' => [
                'storage' => [
                    'type' => 'database',
                    'database' => [
                        'group_column' => 'slot',
                        'group_column_length' => 32,
                    ],
                ],
            ],
            'expected' => [
                'storage' => [
                    'type' => 'database',
                    'database' => [
                        'group_column' => 'slot',
                        'group_column_length' => 32,
                        'connection' => 'default',
                        'table' => 'deploy_task_executions',
                        'auto_create_table' => true,
                        'id_column' => 'id',
                        'id_column_length' => 255,
                        'status_column' => 'status',
                        'executed_at_column' => 'executed_at',
                        'error_column' => 'error',
                        'transaction_mode' => 'all_or_nothing',
                    ],
                    'filesystem' => [
                        'path' => '%kernel.project_dir%/var/deploy-tasks',
                    ],
                    'custom' => [
                        'service' => null,
                        'transaction_mode' => 'none',
                    ],
                ],
                'id_generator' => null,
                'sorter' => null,
                'logger' => null,
                'default_timeout' => 300,
                'events' => ['enabled' => true],
                'lock' => ['enabled' => true, 'ttl' => 3600],
                'generate' => [
                    'directory' => 'src/DeployTasks/Task/',
                    'template' => null,
                    'root_namespace' => 'App',
                ],
                'host' => [
                    'directory' => '%kernel.project_dir%/deploy/host-tasks',
                    'log_path' => '%kernel.project_dir%/.deploy-tasks-host.log',
                    'lock_path' => '%kernel.project_dir%/.deploy-tasks-host.lock',
                ],
            ],
        ];
    }

    public function testLockTtlDefaultIs3600(): void
    {
        $config = self::processConfig([]);
        /** @var array{ttl: int} $lockConfig */
        $lockConfig = $config['lock'];

        self::assertSame(3600, $lockConfig['ttl']);
    }

    public function testLockTtlCustomValueRoundTrips(): void
    {
        $config = self::processConfig(['lock' => ['ttl' => 1800]]);
        /** @var array{ttl: int} $lockConfig */
        $lockConfig = $config['lock'];

        self::assertSame(1800, $lockConfig['ttl']);
    }

    public function testLockTtlRejectsValueBelow60(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig(['lock' => ['ttl' => 59]]);
    }

    public function testLockTtlAccepts60ExactlyAsMinimum(): void
    {
        // Mutant 129: IncrementInteger min(60→61). Value 60 must be accepted.
        $config = self::processConfig(['lock' => ['ttl' => 60]]);
        /** @var array{ttl: int} $lockConfig */
        $lockConfig = $config['lock'];

        self::assertSame(60, $lockConfig['ttl']);
    }

    // -------------------------------------------------------------------------
    // StorageConfigNode — SQL identifier validation (mutants 131–143)
    // PregMatchRemoveCaret: strings that start with a digit must be rejected.
    // PregMatchRemoveDollar: strings that end with an invalid char must be rejected.
    // -------------------------------------------------------------------------

    public function testTableNameStartingWithDigitIsRejected(): void
    {
        // Mutant 131: PregMatchRemoveCaret on 'table'. Without '^', '1abc' would match.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['table' => '1invalid_table']],
        ]);
    }

    public function testTableNameEndingWithInvalidCharIsRejected(): void
    {
        // Mutant 132: PregMatchRemoveDollar on 'table'. Without '$', 'valid@' would match prefix.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['table' => 'valid@invalid']],
        ]);
    }

    public function testIdColumnNameStartingWithDigitIsRejected(): void
    {
        // Mutant 133: PregMatchRemoveCaret on 'id_column'.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['id_column' => '9id']],
        ]);
    }

    public function testIdColumnLengthMinimumIsOne(): void
    {
        // Mutants 134 (DecrementInteger: min→0) and 135 (IncrementInteger: min→2).
        // Value 1 must be accepted; value 0 must be rejected.
        $config = self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['id_column_length' => 1]],
        ]);

        $storage = $config['storage'];
        self::assertIsArray($storage);
        $database = $storage['database'];
        self::assertIsArray($database);
        self::assertSame(1, $database['id_column_length']);
    }

    public function testIdColumnLengthRejectsZero(): void
    {
        // Mutant 134: min(1→0) would accept 0. Without this test the decrement mutant escapes.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['id_column_length' => 0]],
        ]);
    }

    public function testStatusColumnNameStartingWithDigitIsRejected(): void
    {
        // Mutant 136: PregMatchRemoveCaret on 'status_column'.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['status_column' => '1status']],
        ]);
    }

    public function testStatusColumnNameEndingWithInvalidCharIsRejected(): void
    {
        // Mutant 137: PregMatchRemoveDollar on 'status_column'.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['status_column' => 'status@col']],
        ]);
    }

    public function testExecutedAtColumnNameStartingWithDigitIsRejected(): void
    {
        // Mutant 138: PregMatchRemoveCaret on 'executed_at_column'.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['executed_at_column' => '1executed']],
        ]);
    }

    public function testExecutedAtColumnNameEndingWithInvalidCharIsRejected(): void
    {
        // Mutant 139: PregMatchRemoveDollar on 'executed_at_column'.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['executed_at_column' => 'executed@col']],
        ]);
    }

    public function testErrorColumnNameStartingWithDigitIsRejected(): void
    {
        // Mutant 140: PregMatchRemoveCaret on 'error_column'.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['error_column' => '1error']],
        ]);
    }

    public function testErrorColumnNameEndingWithInvalidCharIsRejected(): void
    {
        // Mutant 141: PregMatchRemoveDollar on 'error_column'.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['error_column' => 'error@col']],
        ]);
    }

    public function testGroupColumnNameStartingWithDigitIsRejected(): void
    {
        // Mutant 142: PregMatchRemoveCaret on 'group_column'.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['group_column' => '1group']],
        ]);
    }

    public function testGroupColumnNameEndingWithInvalidCharIsRejected(): void
    {
        // Mutant 143: PregMatchRemoveDollar on 'group_column'.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['group_column' => 'group@col']],
        ]);
    }

    public function testGroupColumnLengthMinimumIsOne(): void
    {
        // Mutants 144 (DecrementInteger: min→0) and 145 (IncrementInteger: min→2).
        $config = self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['group_column_length' => 1]],
        ]);

        $storage = $config['storage'];
        self::assertIsArray($storage);
        $database = $storage['database'];
        self::assertIsArray($database);
        self::assertSame(1, $database['group_column_length']);
    }

    public function testGroupColumnLengthRejectsZero(): void
    {
        // Mutant 144: min(1→0) would accept 0.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['group_column_length' => 0]],
        ]);
    }

    public function testDefaultTimeoutZeroIsAccepted(): void
    {
        // Mutants 146 (IncrementInteger: min(0→1)) and 147 (DecrementInteger: min(0→-1)).
        // Value 0 must be valid (disables timeout check).
        $config = self::processConfig(['default_timeout' => 0]);

        self::assertSame(0, $config['default_timeout']);
    }

    public function testDefaultTimeoutRejectsNegativeValue(): void
    {
        // min(0) on default_timeout: -1 must be rejected.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig(['default_timeout' => -1]);
    }

    public function testUnknownStorageTypeIsRejected(): void
    {
        // enumNode('type') only allows filesystem|database|custom.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig(['storage' => ['type' => 'redis']]);
    }

    // -------------------------------------------------------------------------
    // StorageConfigNode — transaction_mode enum node (database / custom)
    // -------------------------------------------------------------------------

    public function testDatabaseTransactionModePerTaskRoundTrips(): void
    {
        $config = self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['transaction_mode' => 'per_task']],
        ]);

        $storage = $config['storage'];
        self::assertIsArray($storage);
        $database = $storage['database'];
        self::assertIsArray($database);
        self::assertSame('per_task', $database['transaction_mode']);
    }

    public function testCustomTransactionModePerTaskRoundTrips(): void
    {
        $config = self::processConfig([
            'storage' => [
                'type' => 'custom',
                'custom' => ['service' => 'app.storage', 'transaction_mode' => 'per_task'],
            ],
        ]);

        $storage = $config['storage'];
        self::assertIsArray($storage);
        $custom = $storage['custom'];
        self::assertIsArray($custom);
        self::assertSame('per_task', $custom['transaction_mode']);
    }

    public function testUnknownDatabaseTransactionModeIsRejected(): void
    {
        // enumNode('transaction_mode') only allows none|per_task|all_or_nothing.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['transaction_mode' => 'sometimes']],
        ]);
    }

    public function testUnknownCustomTransactionModeIsRejected(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig([
            'storage' => [
                'type' => 'custom',
                'custom' => ['service' => 'app.storage', 'transaction_mode' => 'always'],
            ],
        ]);
    }

    public function testRemovedDatabaseTransactionalFlagIsRejectedAsUnrecognized(): void
    {
        // The transactional/all_or_nothing booleans are replaced by transaction_mode
        // (pre-1.0 breaking, no shim) — the old keys must fail loudly, not silently no-op.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unrecognized option "transactional"/');

        self::processConfig([
            'storage' => ['type' => 'database', 'database' => ['transactional' => true]],
        ]);
    }

    public function testRemovedCustomAllOrNothingFlagIsRejectedAsUnrecognized(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unrecognized option "all_or_nothing"/');

        self::processConfig([
            'storage' => [
                'type' => 'custom',
                'custom' => ['service' => 'app.storage', 'all_or_nothing' => true],
            ],
        ]);
    }

    public function testEmptyGenerateRootNamespaceIsRejected(): void
    {
        // cannotBeEmpty() on generate.root_namespace.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig(['generate' => ['root_namespace' => '']]);
    }

    public function testHostDefaultsMirrorTheRunnerEnvVarDefaults(): void
    {
        // host.directory / host.log_path / host.lock_path are the PHP-side source of truth
        // for the runner's DEPLOY_TASKS_HOST_DIR / STORAGE / LOCK paths.
        $config = self::processConfig([]);

        self::assertSame(
            [
                'directory' => '%kernel.project_dir%/deploy/host-tasks',
                'log_path' => '%kernel.project_dir%/.deploy-tasks-host.log',
                'lock_path' => '%kernel.project_dir%/.deploy-tasks-host.lock',
            ],
            $config['host'],
        );
    }

    public function testGenerateHostDirectoryIsRejectedAsUnrecognized(): void
    {
        // generate.host_directory moved to host.directory (pre-1.0 breaking, no shim).
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig(['generate' => ['host_directory' => '/anywhere']]);
    }

    public function testEmptyHostDirectoryIsRejected(): void
    {
        // cannotBeEmpty() on host.directory.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig(['host' => ['directory' => '']]);
    }

    public function testEmptyHostLogPathIsRejected(): void
    {
        // cannotBeEmpty() on host.log_path.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig(['host' => ['log_path' => '']]);
    }

    public function testEmptyHostLockPathIsRejected(): void
    {
        // cannotBeEmpty() on host.lock_path.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig(['host' => ['lock_path' => '']]);
    }

    /**
     * Malformed identifiers raise InvalidConfigurationException at compile time.
     */
    public function testRejectsMalformedSqlIdentifier(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not a valid SQL identifier/');

        self::processConfig([
            'storage' => [
                'type' => 'database',
                'database' => [
                    'id_column' => 'id"--',
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function processConfig(array $input): array
    {
        $treeBuilder = new TreeBuilder('soviann_deploy_tasks');
        $loader = new DefinitionFileLoader($treeBuilder, new FileLocator([\sys_get_temp_dir()]));
        $configurator = new DefinitionConfigurator($treeBuilder, $loader, __FILE__, __FILE__);

        (new SoviannDeployTasksBundle())->configure($configurator);

        return (new Processor())->process($treeBuilder->buildTree(), [$input]);
    }
}
