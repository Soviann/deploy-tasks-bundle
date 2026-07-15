<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\AllOrNothingFailureException;
use Soviann\DeployTasksBundle\Exception\DeployTasksExceptionInterface;
use Soviann\DeployTasksBundle\Exception\DuplicateTaskIdException;
use Soviann\DeployTasksBundle\Exception\EventListenerException;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
use Soviann\DeployTasksBundle\Exception\MismatchedTaskIdException;
use Soviann\DeployTasksBundle\Exception\StorageException;
use Soviann\DeployTasksBundle\Exception\TaskEnvironmentMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskGroupMismatchException;
use Soviann\DeployTasksBundle\Exception\TaskNotFoundException;
use Soviann\DeployTasksBundle\Exception\TaskReturnedFailureException;

// The marker interface carries no executable code to cover; this test only
// asserts the structural relationship that each exception implements it.
// PHPUnit 11 rejects #[CoversClass] on an interface, so cover nothing.
#[CoversNothing]
final class DeployTasksExceptionInterfaceTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<\Throwable>}>
     */
    public static function provideExceptions(): iterable
    {
        yield 'DuplicateTaskIdException' => [DuplicateTaskIdException::class];
        yield 'TaskNotFoundException' => [TaskNotFoundException::class];
        yield 'TaskGroupMismatchException' => [TaskGroupMismatchException::class];
        yield 'IncompatibleStorageException' => [IncompatibleStorageException::class];
        yield 'StorageException' => [StorageException::class];
        yield 'EventListenerException' => [EventListenerException::class];
        yield 'AllOrNothingFailureException' => [AllOrNothingFailureException::class];
        yield 'TaskEnvironmentMismatchException' => [TaskEnvironmentMismatchException::class];
        yield 'MismatchedTaskIdException' => [MismatchedTaskIdException::class];
        yield 'TaskReturnedFailureException' => [TaskReturnedFailureException::class];
    }

    #[DataProvider('provideExceptions')]
    public function testImplementsMarker(string $exceptionClass): void
    {
        self::assertTrue(
            \is_subclass_of($exceptionClass, DeployTasksExceptionInterface::class),
            \sprintf('%s must implement DeployTasksExceptionInterface', $exceptionClass),
        );
    }
}
