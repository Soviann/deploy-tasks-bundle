<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\DeployTasksBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel;

abstract class AbstractTestKernel extends Kernel
{
    use MicroKernelTrait;

    final public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DeployTasksBundle();
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-'.static::kernelName().'-cache-'.\getmypid().'/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir().'/deploy-tasks-'.static::kernelName().'-logs-'.\getmypid();
    }

    abstract protected static function kernelName(): string;

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
