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

    public function testNormalizeWithinTraversalRejection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the allowed base');

        PathNormalizer::normalizeWithin('../escape', '/srv/app');
    }

    public function testNormalizeWithinSiblingRejection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the allowed base');

        PathNormalizer::normalizeWithin('../myappX', '/srv/myapp');
    }

    public function testNormalizeWithinHappyPath(): void
    {
        self::assertSame(
            '/srv/app/foo/bar',
            PathNormalizer::normalizeWithin('foo/bar', '/srv/app'),
        );
    }

    public function testNormalizeWithinAcceptsBaseWithTrailingSlash(): void
    {
        // When $base already has a trailing slash, the boundary computation must
        // still produce exactly one slash — otherwise `str_starts_with` fails on a
        // valid path (Mutant 159: rtrim removed from boundary, yielding double slash).
        self::assertSame(
            '/srv/app/subdir',
            PathNormalizer::normalizeWithin('subdir', '/srv/app/'),
        );
    }

    public function testNormalizeWithinPathEqualsBase(): void
    {
        // When the resolved path exactly equals the base (no trailing slash),
        // the check `str_starts_with($normalized.'/', $boundary)` passes because
        // we append '/' to $normalized before comparing.
        // Mutant 160 removes the appended '/' from $normalized, which would cause
        // a valid same-directory path to be rejected.
        self::assertSame(
            '/srv/app',
            PathNormalizer::normalizeWithin('.', '/srv/app'),
        );
    }
}
