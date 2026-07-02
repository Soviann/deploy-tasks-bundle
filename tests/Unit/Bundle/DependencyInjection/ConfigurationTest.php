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
                        'transactional' => true,
                        'all_or_nothing' => true,
                    ],
                    'custom' => [
                        'service' => null,
                        'transactional' => false,
                        'all_or_nothing' => false,
                    ],
                ],
                'events' => ['enabled' => true],
                'lock' => ['enabled' => true, 'ttl' => 3600],
                'generate' => [
                    'directory' => 'src/DeployTasks/Task/',
                    'template' => null,
                    'root_namespace' => 'App',
                    'host_directory' => '%kernel.project_dir%/deploy/host-tasks',
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
                        'transactional' => true,
                        'all_or_nothing' => true,
                    ],
                    'custom' => [
                        'service' => null,
                        'transactional' => false,
                        'all_or_nothing' => false,
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
                    'host_directory' => '%kernel.project_dir%/deploy/host-tasks',
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
                        'transactional' => false,
                        'all_or_nothing' => false,
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
                        'transactional' => true,
                        'all_or_nothing' => true,
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
                    'host_directory' => '%kernel.project_dir%/deploy/host-tasks',
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
                        'transactional' => false,
                        'all_or_nothing' => false,
                    ],
                    'custom' => ['service' => 'c', 'transactional' => true, 'all_or_nothing' => true],
                ],
                'events' => ['enabled' => false],
                'lock' => ['enabled' => false, 'ttl' => 1800],
                'generate' => ['directory' => 'src/T/', 'template' => '/tpl.php'],
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
                        'transactional' => false,
                        'all_or_nothing' => false,
                    ],
                    'custom' => [
                        'service' => 'c',
                        'transactional' => true,
                        'all_or_nothing' => true,
                    ],
                ],
                'events' => ['enabled' => false],
                'lock' => ['enabled' => false, 'ttl' => 1800],
                'generate' => [
                    'directory' => 'src/T/',
                    'template' => '/tpl.php',
                    'root_namespace' => 'App',
                    'host_directory' => '%kernel.project_dir%/deploy/host-tasks',
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
                        'transactional' => true,
                        'all_or_nothing' => true,
                    ],
                    'custom' => [
                        'service' => null,
                        'transactional' => false,
                        'all_or_nothing' => false,
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
                    'host_directory' => '%kernel.project_dir%/deploy/host-tasks',
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
                        'transactional' => true,
                        'all_or_nothing' => true,
                    ],
                    'custom' => [
                        'service' => null,
                        'transactional' => false,
                        'all_or_nothing' => false,
                    ],
                ],
                'lock' => ['enabled' => true, 'ttl' => 3600],
                'generate' => [
                    'directory' => 'src/DeployTasks/Task/',
                    'template' => null,
                    'root_namespace' => 'App',
                    'host_directory' => '%kernel.project_dir%/deploy/host-tasks',
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
                        'transactional' => true,
                        'all_or_nothing' => true,
                    ],
                    'custom' => [
                        'service' => null,
                        'transactional' => false,
                        'all_or_nothing' => false,
                    ],
                ],
                'events' => ['enabled' => true],
                'generate' => [
                    'directory' => 'src/DeployTasks/Task/',
                    'template' => null,
                    'root_namespace' => 'App',
                    'host_directory' => '%kernel.project_dir%/deploy/host-tasks',
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
                        'transactional' => true,
                        'all_or_nothing' => true,
                    ],
                    'filesystem' => [
                        'path' => '%kernel.project_dir%/var/deploy-tasks',
                    ],
                    'custom' => [
                        'service' => null,
                        'transactional' => false,
                        'all_or_nothing' => false,
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
                    'host_directory' => '%kernel.project_dir%/deploy/host-tasks',
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

    public function testEmptyGenerateRootNamespaceIsRejected(): void
    {
        // cannotBeEmpty() on generate.root_namespace.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig(['generate' => ['root_namespace' => '']]);
    }

    public function testEmptyGenerateHostDirectoryIsRejected(): void
    {
        // cannotBeEmpty() on generate.host_directory.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        self::processConfig(['generate' => ['host_directory' => '']]);
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
