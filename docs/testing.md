# Testing

## Unit Testing with InMemoryStorage

Use `InMemoryStorage` to test task logic without touching the filesystem or database.

```php
use Soviann\DeployTasks\Storage\InMemoryStorage;
use Soviann\DeployTasks\TaskRunner;
use Soviann\DeployTasks\TaskRegistry;
use Soviann\DeployTasks\DefaultTaskOrderResolver;

$storage = new InMemoryStorage();
$registry = new TaskRegistry([$yourTask]);
$runner = new TaskRunner($registry, $storage, new DefaultTaskOrderResolver());
```

## Functional Testing with a Test Kernel

Boot a minimal kernel with the bundle registered to test DI wiring, commands, and configuration.

```php
use Symfony\Component\HttpKernel\Kernel;
use Soviann\DeployTasksBundle\DeployTasksBundle;

final class TestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
        yield new DeployTasksBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true]);
        $container->extension('deploy_tasks', [
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['path' => sys_get_temp_dir().'/deploy-tasks-test'],
            ],
        ]);
        // Register test tasks with the bundle tag
        $container->services()
            ->set(YourTestTask::class)
            ->tag('deploy_tasks.task');
    }
}
```

## Command Testing

```php
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

$kernel = new TestKernel('test', true);
$kernel->boot();
$application = new Application($kernel);
$command = $application->find('deploytasks:status');
$tester = new CommandTester($command);
$tester->execute([]);
```

## Cleanup

Clean up the temp storage directory in `tearDown()` to avoid test pollution between runs.

```php
protected function tearDown(): void
{
    // Remove temp storage directory recursively
}
```
