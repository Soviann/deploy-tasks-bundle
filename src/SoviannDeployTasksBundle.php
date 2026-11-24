<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle;

use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterCreateSchemaCommandPass;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Classic Bundle + DependencyInjection\SoviannDeployTasksExtension + Configuration
 * layout rather than AbstractBundle (Symfony 6.1+), so the bundle registers the
 * same way on Symfony 5.4 LTS through 8.x. The extension is discovered by the
 * Bundle naming convention.
 */
final class SoviannDeployTasksBundle extends Bundle
{
    public function getPath(): string
    {
        // Package root, so config/, templates/, translations/ resolve correctly if ever added.
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterTasksCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        // After RegisterTasksCompilerPass: its custom-storage validation surfaces a
        // missing/invalid custom service before this pass inspects the same definition.
        $container->addCompilerPass(new RegisterCreateSchemaCommandPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }
}
