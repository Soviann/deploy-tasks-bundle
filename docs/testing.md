# Testing

## Unit Testing with InMemoryStorage

Use `InMemoryStorage` to test task logic without touching the filesystem or database.

```php
use Soviann\DeployTasksBundle\Identifier\TaskDescriptionResolver;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;
use Soviann\DeployTasksBundle\Runner\TaskRegistry;
use Soviann\DeployTasksBundle\Runner\TaskRunner;
use Soviann\DeployTasksBundle\Runner\TransactionMode;
use Soviann\DeployTasksBundle\Sorting\DefaultTaskSorter;
use Soviann\DeployTasksBundle\Storage\InMemory\InMemoryStorage;

$storage = new InMemoryStorage();
$idResolver = new TaskIdResolver();
$registry = new TaskRegistry([$yourTask], $idResolver);
$runner = new TaskRunner(
    $registry,
    $storage,
    new DefaultTaskSorter($idResolver),
    $idResolver,
    new TaskDescriptionResolver(),
    slowTaskThreshold: 300,
    transactionMode: TransactionMode::None,
    lockTtl: 3600,
);
```

`TaskRunner`'s constructor takes eight required arguments — `TaskRegistry $registry`, `TaskStorageInterface $storage`, `TaskSorterInterface $sorter`, `TaskIdResolver $idResolver`, `TaskDescriptionResolver $descriptionResolver`, `int $slowTaskThreshold`, `TransactionMode $transactionMode`, `int $lockTtl` — followed by five optional ones, not all defaulting to `null`: `?EventDispatcherInterface $dispatcher = null`, `?LockFactory $lockFactory = null`, `?string $environment = null`, `ClockInterface $clock = new SystemClock()` (real wall-clock time unless overridden — inject a fake clock for deterministic timestamps in tests), and `?LoggerInterface $logger = null` (a `NullLogger` is used when no logger is given). The example above supplies only the required arguments; add optional ones as needed. A `transactionMode` other than `none` throws `IncompatibleStorageException` unless `$storage` implements `TransactionalStorageInterface`.

## Functional Testing with a Test Kernel

Boot a minimal kernel with the bundle registered to test DI wiring, commands, and configuration.

```php
use Symfony\Component\HttpKernel\Kernel;
use Soviann\DeployTasksBundle\SoviannDeployTasksBundle;

final class TestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
        yield new SoviannDeployTasksBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true]);
        $container->extension('soviann_deploy_tasks', [
            'storage' => [
                'type' => 'filesystem',
                'filesystem' => ['path' => sys_get_temp_dir().'/deploy-tasks-test'],
            ],
        ]);
        // Register test tasks with the bundle tag
        $container->services()
            ->set(YourTestTask::class)
            ->tag('soviann_deploy_tasks.task');
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

## Host-scope tasks

Host tasks are bash scripts invoked by `bin/deploy-tasks-host.sh` — they do **not** go through the Symfony kernel, so `CommandTester` and the kernel harness above do not cover them. Test them at the bash level: invoke the script with a controlled `APP_ENV`, point it at a disposable working directory, and assert against the state of `deploy/host-tasks/` and `.deploy-tasks-host.log`.
