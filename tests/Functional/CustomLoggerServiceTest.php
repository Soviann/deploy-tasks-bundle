<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Soviann\DeployTasksBundle\Tests\Fixtures\ArrayLogger;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Verifies that the bundle respects a user-supplied logger service and does NOT apply
 * the monolog channel tag when the user has set soviann_deploy_tasks.logger explicitly.
 *
 * When logger is null (default), the runner is tagged monolog.logger {channel: soviann_deploy_tasks}
 * and its logger argument is a NULL_ON_INVALID_REFERENCE to the app `logger` service.
 *
 * When logger is set to a custom service, the runner receives a direct Reference to that
 * service with no monolog.logger tag — the Monolog LoggerChannelPass must not silently
 * rewrite the user's explicit choice.
 */
#[CoversClass(SoviannDeployTasksBundle::class)]
#[CoversClass(RegisterTasksCompilerPass::class)]
final class CustomLoggerServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = FilesystemTestHelper::tempDir('deploy-tasks-custom-logger-');
    }

    protected function tearDown(): void
    {
        FilesystemTestHelper::cleanup($this->projectDir);
    }

    public function testRunnerUsesCustomLoggerDirectlyWithoutMonologTag(): void
    {
        $container = $this->buildContainer(['logger' => 'app.custom_logger']);

        $container->register('app.custom_logger', ArrayLogger::class)->setPublic(true);

        $runner = $container->getDefinition('soviann_deploy_tasks.runner');
        $loggerArg = $runner->getArgument('$logger');

        self::assertInstanceOf(Reference::class, $loggerArg);
        self::assertSame('app.custom_logger', (string) $loggerArg);
        self::assertEmpty(
            $runner->getTag('monolog.logger'),
            'runner must NOT carry a monolog.logger tag when user supplies a logger service',
        );
    }

    public function testRunnerUsesMonologChannelTagWhenLoggerIsNull(): void
    {
        $container = $this->buildContainer();

        $runner = $container->getDefinition('soviann_deploy_tasks.runner');
        $loggerArg = $runner->getArgument('$logger');

        self::assertInstanceOf(Reference::class, $loggerArg);
        self::assertSame('logger', (string) $loggerArg);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $loggerArg->getInvalidBehavior());

        $tags = $runner->getTag('monolog.logger');
        self::assertCount(1, $tags, 'runner must carry exactly one monolog.logger tag when bundle owns the logger');
        self::assertSame('soviann_deploy_tasks', $tags[0]['channel'] ?? null);
    }

    public function testDeployTasksLoggerAliasPointsToUserServiceWhenLoggerIsConfigured(): void
    {
        // Mutant 156: MethodCallRemoval removes `$services->alias('soviann_deploy_tasks.logger', $userLoggerId)`.
        // Without that alias, the 'soviann_deploy_tasks.logger' service would not exist in the container.
        $container = $this->buildContainer(['logger' => 'app.custom_logger']);
        $container->register('app.custom_logger', ArrayLogger::class)->setPublic(true);

        self::assertTrue(
            $container->hasAlias('soviann_deploy_tasks.logger'),
            'soviann_deploy_tasks.logger alias must be registered when a custom logger service is configured.',
        );
        self::assertSame(
            'app.custom_logger',
            (string) $container->getAlias('soviann_deploy_tasks.logger'),
            'soviann_deploy_tasks.logger alias must point to the user-configured logger service.',
        );
    }

    /**
     * @param array<string, mixed> $extraConfig
     */
    private function buildContainer(array $extraConfig = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $projectDir = $this->projectDir;
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', $projectDir.'/build');
        $container->setParameter('kernel.cache_dir', $projectDir.'/cache');

        $bundle = new SoviannDeployTasksBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);

        $extension->load([
            \array_merge([
                'storage' => ['type' => 'filesystem'],
                'events' => ['enabled' => false],
                'lock' => ['enabled' => false],
            ], $extraConfig),
        ], $container);

        return $container;
    }
}
