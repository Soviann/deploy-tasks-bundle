<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Exception;

/**
 * Wraps a throwable escaping a Before/After/Failed task-event listener, so callers
 * can distinguish listener bugs from task failures. The original listener error is
 * chained as the previous exception.
 */
final class EventListenerException extends \RuntimeException implements DeployTasksExceptionInterface
{
}
