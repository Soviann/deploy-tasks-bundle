<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Bundle\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\EventsConfigNode;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\LockConfigNode;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration\StorageConfigNode;
use Soviann\DeployTasksBundle\DeployTasksBundle;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;

/**
 * Round-trip snapshot guard for the composed configuration tree.
 *
 * Freezes the processed-config output of every known YAML shape so any future
 * edit to `DeployTasksBundle::configure()` or its per-subtree nodes that shifts
 * a default, renames a key, or drops a field fails here first.
 */
#[CoversClass(DeployTasksBundle::class)]
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
                        'transactional' => false,
                        'all_or_nothing' => false,
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
                        'transactional' => false,
                        'all_or_nothing' => false,
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
                        'transactional' => false,
                        'all_or_nothing' => false,
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
                    'filesystem' => ['path' => '/custom/fs', 'transactional' => true, 'all_or_nothing' => true],
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
                        'transactional' => true,
                        'all_or_nothing' => true,
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
                        'transactional' => false,
                        'all_or_nothing' => false,
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
                        'transactional' => false,
                        'all_or_nothing' => false,
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
                        'transactional' => false,
                        'all_or_nothing' => false,
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
                        'transactional' => false,
                        'all_or_nothing' => false,
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
        $treeBuilder = new TreeBuilder('deploy_tasks');
        $loader = new DefinitionFileLoader($treeBuilder, new FileLocator([\sys_get_temp_dir()]));
        $configurator = new DefinitionConfigurator($treeBuilder, $loader, __FILE__, __FILE__);

        (new DeployTasksBundle())->configure($configurator);

        return (new Processor())->process($treeBuilder->buildTree(), [$input]);
    }
}
