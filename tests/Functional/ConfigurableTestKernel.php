<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Generic functional-test kernel: instead of one kernel subclass per scenario,
 * the extension config and extra service definitions are injected as plain arrays.
 *
 * The cache/log directories are keyed on a hash of the full configuration so two
 * kernels with different configs never share a compiled container — reusing the
 * name-based directories of {@see AbstractTestKernel} would make every config
 * variant collide on the same cache.
 *
 * @phpstan-type ServiceSpec array{
 *     class: class-string,
 *     args?: list<mixed>,
 *     factory?: array{class-string, string},
 *     tags?: list<string>,
 *     public?: bool,
 *     autoconfigure?: bool,
 * }
 */
final class ConfigurableTestKernel extends AbstractTestKernel
{
    /**
     * @param array<string, mixed>       $extensionConfig config for the `soviann_deploy_tasks` extension
     * @param array<string, ServiceSpec> $services        extra service definitions, keyed by service id
     */
    public function __construct(
        string $environment,
        bool $debug,
        private readonly array $extensionConfig = [],
        private readonly array $services = [],
    ) {
        parent::__construct($environment, $debug);
    }

    public function getCacheDir(): string
    {
        $cacheDir = \sys_get_temp_dir()
            .'/deploy-tasks-'.$this->configHash().'-cache-'.\getmypid().'/'.$this->environment;

        // Same pre-create rationale as AbstractTestKernel::getCacheDir().
        if (!\is_dir($cacheDir)) {
            (new Filesystem())->mkdir($cacheDir);
        }

        return $cacheDir;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-'.$this->configHash().'-logs-'.\getmypid();
    }

    protected static function kernelName(): string
    {
        // Unused: getCacheDir()/getLogDir() are overridden with config-hash keys.
        return 'configurable';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', $this->frameworkConfig());
        $container->extension('soviann_deploy_tasks', $this->extensionConfig);

        $services = $container->services();

        foreach ($this->services as $id => $spec) {
            $definition = $services->set($id, $spec['class']);

            if (isset($spec['args'])) {
                $definition->args($spec['args']);
            }

            if (isset($spec['factory'])) {
                $definition->factory($spec['factory']);
            }

            foreach ($spec['tags'] ?? [] as $tag) {
                $definition->tag($tag);
            }

            if ($spec['public'] ?? false) {
                $definition->public();
            }

            if ($spec['autoconfigure'] ?? false) {
                $definition->autoconfigure();
            }
        }
    }

    private function configHash(): string
    {
        return 'configurable-'.\substr(\sha1(\serialize([$this->extensionConfig, $this->services])), 0, 12);
    }
}
