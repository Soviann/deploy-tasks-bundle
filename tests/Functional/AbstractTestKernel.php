<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

abstract class AbstractTestKernel extends Kernel
{
    use MicroKernelTrait;

    final public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SoviannDeployTasksBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        $cacheDir = \sys_get_temp_dir().'/deploy-tasks-'.static::kernelName().'-cache-'.\getmypid().'/'.$this->environment;

        // Pre-create the directory: on Symfony 6.4 the annotations cache warmer
        // writes <cache_dir>/annotations.map during boot before Symfony creates
        // the dir for these throwaway per-test kernels, which otherwise fails with
        // "No such file or directory". mkdir() is idempotent here.
        if (!\is_dir($cacheDir)) {
            (new Filesystem())->mkdir($cacheDir);
        }

        return $cacheDir;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-'.static::kernelName().'-logs-'.\getmypid();
    }

    abstract protected static function kernelName(): string;

    /**
     * These kernels exercise the bundle's services, not HTTP routing. Declaring an
     * empty route configuration stops MicroKernelTrait from importing a default
     * route resource from a `config/` directory that does not exist here — on
     * Symfony 6.4 the router cache warmer loads routes during boot and otherwise
     * fails with FileLocatorFileNotFoundException.
     */
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    protected function frameworkConfig(): array
    {
        return [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ];
    }
}
