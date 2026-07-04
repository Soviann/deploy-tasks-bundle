<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Support;

use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Boots a throwaway kernel whose kernel.project_dir is a disposable temp tree, so
 * host.directory's default (%kernel.project_dir%/deploy/host-tasks) and the
 * host commands' default log path (host.log_path, %kernel.project_dir%/.deploy-tasks-host.log)
 * resolve inside it instead of the bundle's own root.
 *
 * Cache/log dirs are keyed on the full argument list: %kernel.project_dir% (and any
 * host.directory override) is baked into the compiled container, so two kernels built
 * from different arguments must never share a cache. Both dirs live under the
 * /tmp/deploy-tasks-* prefix that the mutation-testing workflow cleans between runs;
 * call cleanupAll() in tearDown to remove every dir this factory created.
 */
final class HostTasksKernelFactory
{
    /** @var list<string> */
    private static array $createdDirs = [];

    public static function boot(string $projectDir, ?string $hostDirectory = null): Kernel
    {
        $key = \substr(\sha1(\serialize([$projectDir, $hostDirectory])), 0, 12).'-'.\getmypid();
        $cacheDir = \sys_get_temp_dir().'/deploy-tasks-kernel-cache-'.$key;
        $logDir = \sys_get_temp_dir().'/deploy-tasks-kernel-logs-'.$key;
        self::$createdDirs[] = $cacheDir;
        self::$createdDirs[] = $logDir;

        $kernel = new class('test', true, $projectDir, $hostDirectory, $cacheDir, $logDir) extends Kernel {
            use MicroKernelTrait;

            public function __construct(
                string $environment,
                bool $debug,
                private readonly string $fakeProjectDir,
                private readonly ?string $hostDirectory,
                private readonly string $fixedCacheDir,
                private readonly string $fixedLogDir,
            ) {
                parent::__construct($environment, $debug);
            }

            public function registerBundles(): iterable
            {
                yield new FrameworkBundle();
                yield new SoviannDeployTasksBundle();
            }

            public function getProjectDir(): string
            {
                return $this->fakeProjectDir;
            }

            public function getCacheDir(): string
            {
                return $this->fixedCacheDir.'/'.$this->environment;
            }

            public function getLogDir(): string
            {
                return $this->fixedLogDir;
            }

            protected function configureContainer(ContainerConfigurator $container): void
            {
                $container->extension('framework', [
                    'test' => true,
                    'secret' => 'test',
                    'http_method_override' => false,
                    'handle_all_throwables' => true,
                    'php_errors' => ['log' => true],
                ]);

                $bundleConfig = [
                    'storage' => [
                        'type' => 'filesystem',
                        'filesystem' => ['path' => $this->fakeProjectDir.'/var/deploy-tasks-storage'],
                    ],
                    'events' => ['enabled' => false],
                    'lock' => ['enabled' => false],
                ];
                if (null !== $this->hostDirectory) {
                    $bundleConfig['host'] = ['directory' => $this->hostDirectory];
                }
                $container->extension('soviann_deploy_tasks', $bundleConfig);

                $container->services()
                    ->set('logger', NullLogger::class)->public();
            }
        };

        $kernel->boot();

        return $kernel;
    }

    /**
     * Remove every cache/log dir created by boot() in this process.
     */
    public static function cleanupAll(): void
    {
        foreach (self::$createdDirs as $dir) {
            FilesystemTestHelper::cleanup($dir);
        }
        self::$createdDirs = [];
    }
}
