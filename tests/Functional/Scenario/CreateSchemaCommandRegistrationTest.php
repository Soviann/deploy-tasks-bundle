<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Scenario;

use Soviann\DeployTasksBundle\Tests\Functional\CustomStorageTestKernel;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Soviann\DeployTasksBundle\Tests\Functional\KernelConfig;
use Symfony\Bundle\FrameworkBundle\Console\Application;

final class CreateSchemaCommandRegistrationTest extends FunctionalTestCase
{
    public function testCommandIsRegisteredWithDbalStorage(): void
    {
        self::useConfigurableKernel(KernelConfig::dbalExtension(), KernelConfig::dbalServices());
        self::bootKernel();

        $app = new Application(self::kernel());
        self::assertTrue($app->has('deploytasks:create-schema'));
    }

    public function testCommandIsAbsentWithFilesystemStorage(): void
    {
        self::bootKernel();

        $app = new Application(self::kernel());
        self::assertFalse($app->has('deploytasks:create-schema'));
    }

    public function testCommandIsAbsentWithCustomStorage(): void
    {
        static::$class = CustomStorageTestKernel::class;
        self::bootKernel();

        $app = new Application(self::kernel());
        self::assertFalse($app->has('deploytasks:create-schema'));
    }
}
