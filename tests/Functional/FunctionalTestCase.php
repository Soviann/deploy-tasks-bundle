<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class FunctionalTestCase extends KernelTestCase
{
    /**
     * @var array{eventsEnabled?: bool, lockEnabled?: bool, extraTasks?: list<class-string<DeployTaskInterface>>}
     */
    protected static array $testKernelOptions = [];

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            try {
                $this->cleanStorage();
            } catch (StorageException) {
                // Test intentionally left storage unavailable (e.g. dropped table); nothing to reset.
            }
        }

        parent::tearDown();
        static::$class = null;
        self::$testKernelOptions = [];
        \restore_exception_handler();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
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

    protected function storage(): TaskStorageInterface
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);

        return $storage;
    }

    protected function runner(): TaskRunner
    {
        $runner = self::getContainer()->get(TaskRunner::class);
        \assert($runner instanceof TaskRunner);

        return $runner;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $opts
     */
    protected function runCommand(string $name, array $args = [], array $opts = []): CommandTester
    {
        $application = new Application(self::kernel());
        $tester = new CommandTester($application->find($name));
        $tester->execute($args, $opts);

        return $tester;
    }

    protected function cleanStorage(): void
    {
        $this->storage()->reset();
    }
}
