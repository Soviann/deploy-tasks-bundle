<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Functional;

use Soviann\DeployTasks\Contract\TaskStorageInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class FunctionalTestCase extends KernelTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        \restore_exception_handler();
    }

    protected function cleanStorage(): void
    {
        $storage = self::getContainer()->get(TaskStorageInterface::class);
        \assert($storage instanceof TaskStorageInterface);
        $storage->reset();
    }
}
