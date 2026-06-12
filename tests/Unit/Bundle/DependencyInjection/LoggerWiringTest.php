<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Bundle\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Pins the wiring contract for the runner's logger argument and the monolog channel tag.
 *
 * When no user override is configured, the runner gets a NULL_ON_INVALID_REFERENCE
 * reference to `logger` and is tagged `monolog.logger { channel: soviann_deploy_tasks }` so
 * MonologBundle's LoggerChannelPass rewrites the reference to the channel-scoped logger.
 * TaskRunner falls back to a NullLogger at runtime when no logger service exists.
 *
 * When the user sets `soviann_deploy_tasks.logger: <service_id>`, the runner receives a direct
 * reference to that service and carries NO `monolog.logger` tag — the channel rewrite
 * must not silently override the user's explicit choice.
 */
#[CoversClass(SoviannDeployTasksBundle::class)]
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

    public function testRunnerReferencesAppLoggerWithNullOnInvalidBehavior(): void
    {
        $container = $this->buildContainer();

        $loggerArg = $container->getDefinition('soviann_deploy_tasks.runner')->getArgument('$logger');

        self::assertInstanceOf(Reference::class, $loggerArg);
        self::assertSame('logger', (string) $loggerArg);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $loggerArg->getInvalidBehavior());
        self::assertMonologChannelTag($container->getDefinition('soviann_deploy_tasks.runner'));
    }

    public function testRunnerReferencesUserLoggerDirectlyWhenUserOverrides(): void
    {
        $container = $this->buildContainer(['logger' => 'my_logger']);

        $runner = $container->getDefinition('soviann_deploy_tasks.runner');
        $loggerArg = $runner->getArgument('$logger');

        self::assertInstanceOf(Reference::class, $loggerArg);
        self::assertSame('my_logger', (string) $loggerArg);
        self::assertEmpty($runner->getTag('monolog.logger'), 'runner must NOT carry a monolog.logger tag when user supplies a logger');
    }

    public function testCompilerPassDoesNotTouchLoggerArgument(): void
    {
        $container = $this->buildContainer();
        $loggerArgBefore = $container->getDefinition('soviann_deploy_tasks.runner')->getArgument('$logger');

        (new RegisterTasksCompilerPass())->process($container);

        $loggerArgAfter = $container->getDefinition('soviann_deploy_tasks.runner')->getArgument('$logger');

        self::assertEquals($loggerArgBefore, $loggerArgAfter);
    }

    private static function assertMonologChannelTag(Definition $runner): void
    {
        $tags = $runner->getTag('monolog.logger');
        self::assertCount(1, $tags, 'runner must carry a single monolog.logger tag');
        self::assertSame('soviann_deploy_tasks', $tags[0]['channel'] ?? null);
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
