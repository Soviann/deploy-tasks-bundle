<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateCommand;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateHostCommand;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Soviann\DeployTasksBundle\Storage\Filesystem\FilesystemStorage;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Ensures every bundle wiring that references %kernel.project_dir% resolves
 * against the supplied project directory, with no literal placeholder leakage.
 */
#[CoversClass(SoviannDeployTasksBundle::class)]
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

        $storage = $container->getDefinition('soviann_deploy_tasks.storage');
        self::assertSame(FilesystemStorage::class, $storage->getClass());

        $resolvedPath = $container->getParameterBag()->resolveValue($storage->getArgument(0));

        self::assertSame($this->projectDir.'/var/deploy-tasks', $resolvedPath);

        // The web-root guard scopes its check to the project dir — the wiring
        // must hand it over, or the guard degrades to the whole-path check.
        $resolvedProjectDir = $container->getParameterBag()->resolveValue($storage->getArgument(1));

        self::assertSame($this->projectDir, $resolvedProjectDir);
    }

    public function testGenerateCommandReceivesResolvedProjectDir(): void
    {
        $container = $this->buildContainer();

        $generate = $container->getDefinition('soviann_deploy_tasks.command.generate');
        self::assertSame(DeployTasksGenerateCommand::class, $generate->getClass());

        $resolved = $container->getParameterBag()->resolveValue($generate->getArgument('$projectDir'));

        self::assertSame($this->projectDir, $resolved);
    }

    public function testGenerateHostCommandReceivesResolvedProjectDir(): void
    {
        $container = $this->buildContainer();

        $generateHost = $container->getDefinition('soviann_deploy_tasks.command.host.generate');
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

        $bundle = new SoviannDeployTasksBundle();
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
