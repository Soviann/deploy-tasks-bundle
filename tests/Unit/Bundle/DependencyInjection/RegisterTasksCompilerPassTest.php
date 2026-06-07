<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Bundle\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Soviann\DeployTasksBundle\Identifier\DefaultTaskIdGenerator;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Tests\Fixtures\AttributeOnlyTask;
use Soviann\DeployTasksBundle\Tests\Fixtures\PredeployTask;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(RegisterTasksCompilerPass::class)]
final class RegisterTasksCompilerPassTest extends TestCase
{
    public function testDuplicateTaskIdThrowsLogicException(): void
    {
        $container = $this->baseContainer();

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

    public function testTaskIdExceedingConfiguredColumnLengthThrows(): void
    {
        $container = $this->baseContainer();
        $this->withDbalColumnLengths($container, idColumnLength: 3, groupColumnLength: 128);

        $def = new Definition(AttributeOnlyTask::class);
        $def->addTag('deploy_tasks.task');
        $container->setDefinition('service.long_id', $def);

        $pass = new RegisterTasksCompilerPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/"attribute_only".*exceeding the configured id_column_length of 3/');

        $pass->process($container);
    }

    public function testTaskGroupExceedingConfiguredColumnLengthThrows(): void
    {
        $container = $this->baseContainer();
        $this->withDbalColumnLengths($container, idColumnLength: 255, groupColumnLength: 3);

        $def = new Definition(PredeployTask::class);
        $def->addTag('deploy_tasks.task');
        $container->setDefinition('service.long_group', $def);

        $pass = new RegisterTasksCompilerPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/group "predeploy".*exceeding the configured group_column_length of 3/');

        $pass->process($container);
    }

    public function testWithinConfiguredColumnLengthsPasses(): void
    {
        $container = $this->baseContainer();
        $this->withDbalColumnLengths($container, idColumnLength: 255, groupColumnLength: 128);

        $def = new Definition(PredeployTask::class);
        $def->addTag('deploy_tasks.task');
        $container->setDefinition('service.ok', $def);

        $pass = new RegisterTasksCompilerPass();
        $pass->process($container);

        $this->expectNotToPerformAssertions();
    }

    /**
     * Builds a container with the services and parameters the pass needs to run:
     * id resolver/generator (for ID resolution) and the runner plus its optional
     * dependency flags (for wireOptionalDependencies).
     */
    private function baseContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->setDefinition('deploy_tasks.id_resolver', new Definition(TaskIdResolver::class));
        $container->setDefinition('deploy_tasks.id_generator', new Definition(DefaultTaskIdGenerator::class));
        $container->setDefinition('deploy_tasks.runner', new Definition('Soviann\DeployTasksBundle\Runner\TaskRunner'));
        $container->setParameter('deploy_tasks.events.enabled', false);
        $container->setParameter('deploy_tasks.lock.enabled', false);

        return $container;
    }

    /**
     * Registers the database storage configuration so the pass treats storage as
     * database-backed and reads the column lengths from it.
     */
    private function withDbalColumnLengths(ContainerBuilder $container, int $idColumnLength, int $groupColumnLength): void
    {
        $definition = new Definition(DbalStorageConfiguration::class);
        $definition->setArgument('$idColumnLength', $idColumnLength);
        $definition->setArgument('$groupColumnLength', $groupColumnLength);

        $container->setDefinition('deploy_tasks.storage.configuration', $definition);
    }
}
