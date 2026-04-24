<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Bundle\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Soviann\DeployTasksBundle\DeployTasksBundle;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Pins the wiring contract for the runner's logger argument and the monolog channel tag.
 *
 * Runs the extension + RegisterTasksCompilerPass directly instead of a full
 * `$container->compile()` — the full pipeline would inline/remove the runner service,
 * and these assertions target definition-time wiring, not the runtime container.
 */
#[CoversClass(DeployTasksBundle::class)]
#[CoversClass(RegisterTasksCompilerPass::class)]
final class LoggerWiringTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = FilesystemTestHelper::tempDir('deploy-tasks-logger-wiring-');
    }

    protected function tearDown(): void
    {
        FilesystemTestHelper::cleanup($this->projectDir);
    }

    public function testRunnerUsesNullLoggerWhenAppHasNoLogger(): void
    {
        $container = $this->buildContainer();
        (new RegisterTasksCompilerPass())->process($container);

        $runner = $container->getDefinition('deploy_tasks.runner');
        $loggerArg = $runner->getArgument(11);

        self::assertInstanceOf(Reference::class, $loggerArg);
        self::assertSame('deploy_tasks.null_logger', (string) $loggerArg);
        self::assertMonologChannelTag($runner);
        self::assertInternalParameterCleaned($container);
    }

    public function testRunnerUsesAppLoggerWhenAvailableAndNotOverridden(): void
    {
        $container = $this->buildContainer();
        $this->registerStubLogger($container, 'logger');
        (new RegisterTasksCompilerPass())->process($container);

        $runner = $container->getDefinition('deploy_tasks.runner');
        $loggerArg = $runner->getArgument(11);

        self::assertInstanceOf(Reference::class, $loggerArg);
        self::assertSame('logger', (string) $loggerArg);
        self::assertMonologChannelTag($runner);
        self::assertInternalParameterCleaned($container);
    }

    public function testRunnerUsesDeployTasksLoggerAliasWhenUserOverrides(): void
    {
        $container = $this->buildContainer(['logger' => 'my_logger']);
        // Also register the app logger to prove override wins over auto-detection.
        $this->registerStubLogger($container, 'logger');
        $this->registerStubLogger($container, 'my_logger');
        (new RegisterTasksCompilerPass())->process($container);

        $runner = $container->getDefinition('deploy_tasks.runner');
        $loggerArg = $runner->getArgument(11);

        self::assertInstanceOf(Reference::class, $loggerArg);
        self::assertSame('deploy_tasks.logger', (string) $loggerArg);
        self::assertSame('my_logger', (string) $container->getAlias('deploy_tasks.logger'));
        self::assertMonologChannelTag($runner);
        self::assertInternalParameterCleaned($container);
    }

    private static function assertMonologChannelTag(Definition $runner): void
    {
        $tags = $runner->getTag('monolog.logger');
        self::assertCount(1, $tags, 'runner must carry a single monolog.logger tag');
        self::assertSame('deploy_tasks', $tags[0]['channel'] ?? null);
    }

    private static function assertInternalParameterCleaned(ContainerBuilder $container): void
    {
        self::assertFalse(
            $container->hasParameter('deploy_tasks.logger.user_overridden'),
            'deploy_tasks.logger.user_overridden must be removed after the compiler pass runs',
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

        $bundle = new DeployTasksBundle();
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

    private function registerStubLogger(ContainerBuilder $container, string $serviceId): void
    {
        $container->setDefinition($serviceId, new Definition(NullLogger::class));
        // Keep the interface alias aligned so autowiring-style lookups still resolve if ever needed.
        if (!$container->hasAlias(LoggerInterface::class)) {
            $container->setAlias(LoggerInterface::class, new Alias($serviceId));
        }
    }
}
