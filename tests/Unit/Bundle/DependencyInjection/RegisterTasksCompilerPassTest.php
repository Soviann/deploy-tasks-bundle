<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Tests\Unit\Bundle\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasks\DefaultTaskIdGenerator;
use Soviann\DeployTasks\DefaultTaskIdResolver;
use Soviann\DeployTasks\Tests\Fixtures\AttributeOnlyTask;
use Soviann\DeployTasksBundle\DependencyInjection\RegisterTasksCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(RegisterTasksCompilerPass::class)]
final class RegisterTasksCompilerPassTest extends TestCase
{
    public function testDuplicateTaskIdThrowsLogicException(): void
    {
        $container = new ContainerBuilder();

        // Register the default resolver so validation runs
        $resolverDef = new Definition(DefaultTaskIdResolver::class);
        $container->setDefinition('deploy_tasks.id_resolver', $resolverDef);

        $generatorDef = new Definition(DefaultTaskIdGenerator::class);
        $container->setDefinition('deploy_tasks.id_generator', $generatorDef);

        // Register the runner (needed by wireOptionalDependencies)
        $runnerDef = new Definition('Soviann\DeployTasks\TaskRunner');
        $container->setDefinition('deploy_tasks.runner', $runnerDef);
        $container->setParameter('deploy_tasks.events.enabled', false);
        $container->setParameter('deploy_tasks.lock.enabled', false);

        // Two services with the same attribute ID ('attribute_only')
        $def1 = new Definition(AttributeOnlyTask::class);
        $def1->addTag('deploy_tasks.task');
        $container->setDefinition('service.first', $def1);

        $def2 = new Definition(AttributeOnlyTask::class);
        $def2->addTag('deploy_tasks.task');
        $container->setDefinition('service.second', $def2);

        $pass = new RegisterTasksCompilerPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate deploy task ID "attribute_only"/');

        $pass->process($container);
    }
}
