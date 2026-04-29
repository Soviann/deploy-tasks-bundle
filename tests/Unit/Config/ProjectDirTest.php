<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateHostCommand;
use Soviann\DeployTasksBundle\DeployTasksBundle;
use Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Ensures every bundle wiring that references %kernel.project_dir% resolves
 * against the supplied project directory, with no literal placeholder leakage.
 */
#[CoversClass(DeployTasksBundle::class)]
final class ProjectDirTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = FilesystemTestHelper::tempDir('deploy-tasks-project-dir-');
    }

    protected function tearDown(): void
    {
        FilesystemTestHelper::cleanup($this->projectDir);
    }

    public function testFilesystemStoragePathResolvesToProjectDir(): void
    {
        $container = $this->buildContainer();

        $storage = $container->getDefinition('deploy_tasks.storage');
        self::assertSame(FilesystemStorage::class, $storage->getClass());

        $resolvedPath = $container->getParameterBag()->resolveValue($storage->getArgument(0));

        self::assertSame($this->projectDir.'/var/deploy-tasks', $resolvedPath);
    }

    public function testGenerateCommandReceivesResolvedProjectDir(): void
    {
        $container = $this->buildContainer();

        $generate = $container->getDefinition('deploy_tasks.command.generate');
        self::assertSame(DeployTasksGenerateCommand::class, $generate->getClass());

        $resolved = $container->getParameterBag()->resolveValue($generate->getArgument(3));

        self::assertSame($this->projectDir, $resolved);
    }

    public function testGenerateHostCommandReceivesResolvedProjectDir(): void
    {
        $container = $this->buildContainer();

        $generateHost = $container->getDefinition('deploy_tasks.command.generate.host');
        self::assertSame(DeployTasksGenerateHostCommand::class, $generateHost->getClass());

        $resolved = $container->getParameterBag()->resolveValue($generateHost->getArgument('$projectDir'));

        self::assertSame($this->projectDir, $resolved);
    }

    private function buildContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.project_dir', $this->projectDir);
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', $this->projectDir.'/build');
        $container->setParameter('kernel.cache_dir', $this->projectDir.'/cache');

        $bundle = new DeployTasksBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            [
                'storage' => ['type' => 'filesystem'],
                'events' => ['enabled' => false],
                'lock' => ['enabled' => false],
            ],
        ], $container);

        return $container;
    }
}
