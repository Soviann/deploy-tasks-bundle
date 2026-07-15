<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
use Soviann\DeployTasksBundle\Runner\TransactionMode;
use Soviann\DeployTasksBundle\Storage\TransactionalStorageInterface;

#[CoversClass(IncompatibleStorageException::class)]
final class IncompatibleStorageExceptionTest extends TestCase
{
    public function testExtendsLogicException(): void
    {
        $parents = \class_parents(IncompatibleStorageException::class);
        self::assertNotFalse($parents);
        self::assertContains(\LogicException::class, $parents);
    }

    public function testModeMessageMentionsConfigValueAndStorageClass(): void
    {
        $exception = IncompatibleStorageException::modeRequiresTransactional(
            TransactionMode::AllOrNothing,
            'App\\Custom\\MyStorage',
        );

        self::assertStringContainsString('transaction_mode: all_or_nothing', $exception->getMessage());
        self::assertStringContainsString('App\\Custom\\MyStorage', $exception->getMessage());
    }

    public function testModeMessageNamesThePerTaskModeToo(): void
    {
        $exception = IncompatibleStorageException::modeRequiresTransactional(
            TransactionMode::PerTask,
            'App\\Custom\\MyStorage',
        );

        self::assertStringContainsString('transaction_mode: per_task', $exception->getMessage());
    }

    public function testModeMessageDoesNotLeakInternalInterfaceFqcn(): void
    {
        $exception = IncompatibleStorageException::modeRequiresTransactional(
            TransactionMode::AllOrNothing,
            'App\\Custom\\MyStorage',
        );

        self::assertStringNotContainsString(TransactionalStorageInterface::class, $exception->getMessage());
    }

    public function testModeMessagePointsAtRemediation(): void
    {
        $exception = IncompatibleStorageException::modeRequiresTransactional(
            TransactionMode::AllOrNothing,
            'App\\Custom\\MyStorage',
        );

        self::assertStringContainsString('storage.type: database', $exception->getMessage());
        self::assertStringContainsString('transactions', $exception->getMessage());
    }

    public function testOptOutConflictMessageNamesTaskAndRemediation(): void
    {
        $exception = IncompatibleStorageException::taskOptOutConflictsWithAllOrNothing('App\\Task\\SeedTask');

        self::assertStringContainsString('App\\Task\\SeedTask', $exception->getMessage());
        self::assertStringContainsString('transactional: false', $exception->getMessage());
        self::assertStringContainsString('all_or_nothing', $exception->getMessage());
        self::assertStringContainsString('transaction_mode: per_task', $exception->getMessage());
    }

    public function testOptInConflictMessageNamesTaskAndRemediation(): void
    {
        $exception = IncompatibleStorageException::taskOptInConflictsWithModeNone('App\\Task\\SeedTask');

        self::assertStringContainsString('App\\Task\\SeedTask', $exception->getMessage());
        self::assertStringContainsString('transactional: true', $exception->getMessage());
        self::assertStringContainsString('"none"', $exception->getMessage());
        self::assertStringContainsString('transaction_mode: per_task', $exception->getMessage());
    }
}
