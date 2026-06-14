<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Soviann\DeployTasksBundle\Command\DeployTasksGenerateHostCommand;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;
use Soviann\DeployTasksBundle\Tests\Support\FilesystemTestHelper;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Verifies that soviann_deploy_tasks.generate.host_directory is honoured at kernel-boot time
 * and that the default value (%kernel.project_dir%/deploy-tasks-host) is resolved correctly.
 */
#[CoversClass(SoviannDeployTasksBundle::class)]
#[CoversClass(DeployTasksGenerateHostCommand::class)]
final class HostDirectoryConfigTest extends KernelTestCase
{
    /** @var string Project dir used as the fake root for both test cases */
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = \sys_get_temp_dir().'/deploy-tasks-host-dir-cfg-'.\uniqid();
        \mkdir($this->projectDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        FilesystemTestHelper::cleanup($this->projectDir);
        \restore_exception_handler();
    }

    // -----------------------------------------------------------------------
    // Case 1: configured host_directory is honoured
    // -----------------------------------------------------------------------

    public function testConfiguredHostDirectoryIsUsed(): void
    {
        $projectDir = $this->projectDir;
        // A sub-directory inside the fake project root so the path-traversal guard is satisfied.
        $configuredRelative = 'var/test-host-tasks';
        $expectedAbsolute = $projectDir.'/'.$configuredRelative;

        $kernel = new class('test', true, $projectDir, $configuredRelative) extends Kernel {
            use MicroKernelTrait;

            public function __construct(
                string $environment,
                bool $debug,
                private readonly string $fakeProjectDir,
                private readonly string $hostRelativeDir,
            ) {
                parent::__construct($environment, $debug);
            }

            public function registerBundles(): iterable
            {
                yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
                yield new SoviannDeployTasksBundle();
            }

            public function getProjectDir(): string
            {
                return $this->fakeProjectDir;
            }

            public function getCacheDir(): string
            {
                return \sys_get_temp_dir().'/host-dir-cfg-custom-cache-'.\getmypid().'/'.$this->environment;
            }

            public function getLogDir(): string
            {
                return \sys_get_temp_dir().'/host-dir-cfg-custom-logs-'.\getmypid();
            }

            protected function configureContainer(ContainerConfigurator $container): void
            {
                $container->extension('framework', [
                    'test' => true,
                    'secret' => 'test',
                    'http_method_override' => false,
                    'handle_all_throwables' => true,
                    'php_errors' => ['log' => true],
                ]);

                $container->extension('soviann_deploy_tasks', [
                    'storage' => [
                        'type' => 'filesystem',
                        'filesystem' => ['path' => $this->fakeProjectDir.'/var/deploy-tasks-storage'],
                    ],
                    'events' => ['enabled' => false],
                    'lock' => ['enabled' => false],
                    'generate' => [
                        // Absolute path so the container resolves it as-is (no %kernel.project_dir% expansion needed).
                        'host_directory' => $this->fakeProjectDir.'/'.$this->hostRelativeDir,
                    ],
                ]);

                $container->services()
                    ->set('logger', NullLogger::class)->public();
            }
        };

        $kernel->boot();
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('deploytasks:generate:host'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $files = \glob($expectedAbsolute.'/deploy_task_*.sh');
        self::assertNotFalse($files);
        self::assertCount(1, $files, 'Expected exactly one generated stub in the configured host_directory.');

        $kernel->shutdown();
    }

    // -----------------------------------------------------------------------
    // Case 2: default value (%kernel.project_dir%/deploy-tasks-host) is resolved
    // -----------------------------------------------------------------------

    public function testDefaultHostDirectoryIsResolvedFromKernelProjectDir(): void
    {
        $projectDir = $this->projectDir;

        $kernel = new class('test', true, $projectDir) extends Kernel {
            use MicroKernelTrait;

            public function __construct(
                string $environment,
                bool $debug,
                private readonly string $fakeProjectDir,
            ) {
                parent::__construct($environment, $debug);
            }

            public function registerBundles(): iterable
            {
                yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
                yield new SoviannDeployTasksBundle();
            }

            public function getProjectDir(): string
            {
                return $this->fakeProjectDir;
            }

            public function getCacheDir(): string
            {
                return \sys_get_temp_dir().'/host-dir-cfg-default-cache-'.\getmypid().'/'.$this->environment;
            }

            public function getLogDir(): string
            {
                return \sys_get_temp_dir().'/host-dir-cfg-default-logs-'.\getmypid();
            }

            protected function configureContainer(ContainerConfigurator $container): void
            {
                $container->extension('framework', [
                    'test' => true,
                    'secret' => 'test',
                    'http_method_override' => false,
                    'handle_all_throwables' => true,
                    'php_errors' => ['log' => true],
                ]);

                $container->extension('soviann_deploy_tasks', [
                    'storage' => [
                        'type' => 'filesystem',
                        'filesystem' => ['path' => $this->fakeProjectDir.'/var/deploy-tasks-storage'],
                    ],
                    'events' => ['enabled' => false],
                    'lock' => ['enabled' => false],
                    // No generate.host_directory — default (%kernel.project_dir%/deploy/host-tasks) applies.
                ]);

                $container->services()
                    ->set('logger', NullLogger::class)->public();
            }
        };

        $kernel->boot();
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('deploytasks:generate:host'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        // The default config value '%kernel.project_dir%/deploy/host-tasks' must resolve
        // to the fake project dir — NOT to the bundle's own directory.
        $expectedDir = $projectDir.'/deploy/host-tasks';
        $files = \glob($expectedDir.'/deploy_task_*.sh');
        self::assertNotFalse($files);
        self::assertCount(
            1,
            $files,
            'Expected exactly one stub in the default deploy/host-tasks directory under project dir.',
        );

        $kernel->shutdown();
    }
}
