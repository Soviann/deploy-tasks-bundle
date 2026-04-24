<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class FunctionalTestCase extends KernelTestCase
{
    /**
     * @var array{eventsEnabled?: bool, lockEnabled?: bool, extraTasks?: list<class-string<DeployTaskInterface>>}
     */
    protected static array $testKernelOptions = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        self::$testKernelOptions = [];
        \restore_exception_handler();
    }

    protected static function kernel(): KernelInterface
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface, 'Kernel must be booted before access.');

        return $kernel;
    }

    // @phpstan-ignore missingType.iterableValue (contravariance with parent's untyped array)
    protected static function createKernel(array $options = []): KernelInterface
    {
        if (null === static::$class) {
            static::$class = static::getKernelClass();
        }

        $environment = \is_string($options['environment'] ?? null) ? $options['environment'] : 'test';
        $debug = \is_bool($options['debug'] ?? null) ? $options['debug'] : true;

        if (TestKernel::class === static::$class && [] !== self::$testKernelOptions) {
            return new TestKernel(
                $environment,
                $debug,
                eventsEnabled: self::$testKernelOptions['eventsEnabled'] ?? false,
                lockEnabled: self::$testKernelOptions['lockEnabled'] ?? false,
                extraTasks: self::$testKernelOptions['extraTasks'] ?? [],
            );
        }

        $class = static::$class;
        $kernel = new $class($environment, $debug);
        \assert($kernel instanceof KernelInterface);

        return $kernel;
    }

    protected function cleanStorage(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $storage->reset();
    }
}
