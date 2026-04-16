<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Exception\StorageException;

#[CoversClass(StorageException::class)]
final class StorageExceptionTest extends TestCase
{
    public function testReadErrorFactory(): void
    {
        $previous = new \RuntimeException('connection lost');
        $exception = StorageException::readError('task.seed', $previous);

        self::assertInstanceOf(StorageException::class, $exception);
        self::assertSame('Failed to read execution record for task "task.seed".', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testWriteErrorFactory(): void
    {
        $previous = new \RuntimeException('disk full');
        $exception = StorageException::writeError('task.migrate', $previous);

        self::assertInstanceOf(StorageException::class, $exception);
        self::assertSame('Failed to write execution record for task "task.migrate".', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
