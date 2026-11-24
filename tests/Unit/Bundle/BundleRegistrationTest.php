<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Bundle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DependencyInjection\Configuration;
use Soviann\DeployTasksBundle\DependencyInjection\SoviannDeployTasksExtension;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Pins the classic bundle registration (Bundle + Extension + Configuration) that
 * replaced AbstractBundle so the bundle also loads on Symfony 5.4 LTS: the
 * extension is discovered by convention under the unchanged `soviann_deploy_tasks`
 * config root, and exposes the configuration tree (config:dump-reference).
 */
#[CoversClass(SoviannDeployTasksBundle::class)]
#[CoversClass(SoviannDeployTasksExtension::class)]
final class BundleRegistrationTest extends TestCase
{
    public function testBundleExposesTheExtensionUnderTheSoviannDeployTasksAlias(): void
    {
        $extension = (new SoviannDeployTasksBundle())->getContainerExtension();

        self::assertInstanceOf(SoviannDeployTasksExtension::class, $extension);
        self::assertSame('soviann_deploy_tasks', $extension->getAlias());
    }

    public function testExtensionExposesTheBundleConfigurationTree(): void
    {
        $configuration = (new SoviannDeployTasksExtension())->getConfiguration([], new ContainerBuilder());

        self::assertInstanceOf(Configuration::class, $configuration);
        self::assertSame('soviann_deploy_tasks', $configuration->getConfigTreeBuilder()->buildTree()->getName());
    }

    public function testBundlePathIsThePackageRoot(): void
    {
        self::assertFileExists((new SoviannDeployTasksBundle())->getPath().'/composer.json');
    }
}
