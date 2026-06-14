<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Helper;

use Symfony\Component\Filesystem\Path;

/**
 * @internal
 */
final class PathNormalizer
{
    /**
     * Canonicalizes a path WITHOUT a containment check. Use normalizeWithin()
     * when the result must stay inside a known base directory.
     */
    public static function normalize(string $path): string
    {
        return Path::canonicalize(\rtrim($path, '/'));
    }

    /**
     * Canonicalizes $path and asserts it stays within $base.
     *
     * @throws \InvalidArgumentException when $path resolves outside $base
     */
    public static function normalizeWithin(string $path, string $base): string
    {
        $normalized = self::normalize(\rtrim($base, '/').'/'.$path);
        $boundary = \rtrim($base, '/').'/';

        if (!\str_starts_with($normalized.'/', $boundary)) {
            throw new \InvalidArgumentException(\sprintf('Path "%s" resolves to "%s", which is outside the allowed base "%s".', $path, $normalized, $base));
        }

        return $normalized;
    }

    /**
     * Resolves $path to its canonical absolute form for display, falling back to
     * the original value when realpath() cannot resolve it (e.g. it no longer exists).
     */
    public static function displayPath(string $path): string
    {
        $resolved = \realpath($path);

        return false !== $resolved ? $resolved : $path;
    }
}
