<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\CoversNothing;
use Soviann\DeployTasksBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;

#[CoversNothing]
final class DeployCreateSchemaCommandNotRegisteredTest extends FunctionalTestCase
{
    public function testCommandIsNotRegisteredWhenStorageIsNotSchemaManageable(): void
    {
        self::bootKernel();
        $application = new Application(self::kernel());

        $this->expectException(CommandNotFoundException::class);
        $application->find('deploytasks:create-schema');
    }
}
