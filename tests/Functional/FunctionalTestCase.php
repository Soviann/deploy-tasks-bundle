<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use Soviann\DeployTasksBundle\Storage\TaskStorageInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class FunctionalTestCase extends KernelTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        \restore_exception_handler();
    }

    protected static function kernel(): KernelInterface
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface, 'Kernel must be booted before access.');

        return $kernel;
    }

    protected function cleanStorage(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $storage->reset();
    }
}
