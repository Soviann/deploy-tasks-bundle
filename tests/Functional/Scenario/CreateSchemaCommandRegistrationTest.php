<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Tests\Functional\CustomStorageTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\DbalTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

final class CreateSchemaCommandRegistrationTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        \restore_exception_handler();
    }

    public function testCommandIsRegisteredWithDbalStorage(): void
    {
        $kernel = new DbalTestKernel('test', true);
        $kernel->boot();
        $app = new Application($kernel);
        self::assertTrue($app->has('deploytasks:create-schema'));
        $kernel->shutdown();
    }

    public function testCommandIsAbsentWithFilesystemStorage(): void
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();
        $app = new Application($kernel);
        self::assertFalse($app->has('deploytasks:create-schema'));
        $kernel->shutdown();
    }

    public function testCommandIsAbsentWithCustomStorage(): void
    {
        $kernel = new CustomStorageTestKernel('test', true);
        $kernel->boot();
        $app = new Application($kernel);
        self::assertFalse($app->has('deploytasks:create-schema'));
        $kernel->shutdown();
    }
}
