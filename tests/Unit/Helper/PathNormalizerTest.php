<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Helper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Helper\PathNormalizer;

final class PathNormalizerTest extends TestCase
{
    #[DataProvider('providePaths')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, PathNormalizer::normalize($input));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function providePaths(): iterable
    {
        yield 'strips trailing slash' => ['foo/bar/', 'foo/bar'];
        yield 'collapses current-directory segments' => ['foo/./bar', 'foo/bar'];
        yield 'collapses duplicated separators' => ['foo//bar', 'foo/bar'];
        yield 'resolves parent-directory segments' => ['foo/../baz', 'baz'];
        yield 'preserves leading parent traversal' => ['../foo', '../foo'];
        yield 'preserves absolute prefix' => ['/abs/path/', '/abs/path'];
        yield 'keeps single relative segment' => ['foo', 'foo'];
    }
}
