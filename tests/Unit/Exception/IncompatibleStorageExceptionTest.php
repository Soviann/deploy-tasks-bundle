<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\IncompatibleStorageException;
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

    public function testAllOrNothingMessageMentionsConfigKeyAndStorageClass(): void
    {
        $exception = IncompatibleStorageException::allOrNothingRequiresTransactional('App\\Custom\\MyStorage');

        self::assertStringContainsString('all_or_nothing', $exception->getMessage());
        self::assertStringContainsString('App\\Custom\\MyStorage', $exception->getMessage());
    }

    public function testAllOrNothingMessageDoesNotLeakInternalInterfaceFqcn(): void
    {
        $exception = IncompatibleStorageException::allOrNothingRequiresTransactional('App\\Custom\\MyStorage');

        self::assertStringNotContainsString(TransactionalStorageInterface::class, $exception->getMessage());
    }

    public function testAllOrNothingMessagePointsAtRemediation(): void
    {
        $exception = IncompatibleStorageException::allOrNothingRequiresTransactional('App\\Custom\\MyStorage');

        self::assertStringContainsString('storage.type: database', $exception->getMessage());
        self::assertStringContainsString('transactions', $exception->getMessage());
    }
}
