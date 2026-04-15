# Events

Requires `symfony/event-dispatcher`. Events are enabled by default when the dispatcher is available.

```yaml
deploy_tasks:
    events:
        enabled: true  # default
```

## Event Classes

All events are in the `Soviann\DeployTasks\Event\` namespace.

| Event | When | Properties |
|---|---|---|
| `BeforeTaskEvent` | Before each task runs | `string $taskId`, `DeployTaskInterface $task` |
| `AfterTaskEvent` | After non-exceptional execution | `string $taskId`, `DeployTaskInterface $task`, `int $result`, `float $duration` |
| `TaskFailedEvent` | On exception | `string $taskId`, `DeployTaskInterface $task`, `\Throwable $exception`, `float $duration` |

## Listener Example

```php
use Soviann\DeployTasks\Event\AfterTaskEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class LogTaskExecutionListener
{
    public function __invoke(AfterTaskEvent $event): void
    {
        // Log task execution: $event->taskId, $event->duration
    }
}
```

## Graceful Degradation

If `symfony/event-dispatcher` is not installed or events are disabled, the runner skips event dispatching silently. No errors are thrown.

To disable events even when the dispatcher is available:

```yaml
deploy_tasks:
    events:
        enabled: false
```
