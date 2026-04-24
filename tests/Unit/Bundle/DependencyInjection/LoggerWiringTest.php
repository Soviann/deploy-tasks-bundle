<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Bundle\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\DependencyInjection\Compiler\RegisterTasksCompilerPass;
use Soviann\DeployTasksBundle\DeployTasksBundle;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Pins the wiring contract for the runner's logger argument and the monolog channel tag.
 *
 * The runner is injected with a NULL_ON_INVALID_REFERENCE reference to the app `logger`
 * service when no user override is configured, or with the `deploy_tasks.logger` alias
 * when the user sets `deploy_tasks.logger: <service_id>`. No compiler pass is involved
 * in logger selection — the reference is wired at extension load so that
 * MonologBundle's LoggerChannelPass can rewrite the literal `logger` reference to the
 * channel-scoped logger via the runner's `monolog.logger` tag regardless of pass
 * ordering, and TaskRunner falls back to a NullLogger at runtime when the app has no
 * logger service.
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

    public function testRunnerReferencesAppLoggerWithNullOnInvalidBehavior(): void
    {
        $container = $this->buildContainer();

        $loggerArg = $container->getDefinition('deploy_tasks.runner')->getArgument(11);

        self::assertInstanceOf(Reference::class, $loggerArg);
        self::assertSame('logger', (string) $loggerArg);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $loggerArg->getInvalidBehavior());
        self::assertMonologChannelTag($container->getDefinition('deploy_tasks.runner'));
    }

    public function testRunnerReferencesDeployTasksLoggerAliasWhenUserOverrides(): void
    {
        $container = $this->buildContainer(['logger' => 'my_logger']);

        $runner = $container->getDefinition('deploy_tasks.runner');
        $loggerArg = $runner->getArgument(11);

        self::assertInstanceOf(Reference::class, $loggerArg);
        self::assertSame('deploy_tasks.logger', (string) $loggerArg);
        self::assertSame('my_logger', (string) $container->getAlias('deploy_tasks.logger'));
        self::assertMonologChannelTag($runner);
    }

    public function testCompilerPassDoesNotTouchLoggerArgument(): void
    {
        $container = $this->buildContainer();
        $loggerArgBefore = $container->getDefinition('deploy_tasks.runner')->getArgument(11);

        (new RegisterTasksCompilerPass())->process($container);

        $loggerArgAfter = $container->getDefinition('deploy_tasks.runner')->getArgument(11);

        self::assertEquals($loggerArgBefore, $loggerArgAfter);
    }

    private static function assertMonologChannelTag(Definition $runner): void
    {
        $tags = $runner->getTag('monolog.logger');
        self::assertCount(1, $tags, 'runner must carry a single monolog.logger tag');
        self::assertSame('deploy_tasks', $tags[0]['channel'] ?? null);
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
}
