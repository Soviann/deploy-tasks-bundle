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
        return self::assertWithin(self::normalize(\rtrim($base, '/').'/'.$path), $base);
    }

    /**
     * Asserts an already-canonical absolute $path stays within $base, and returns it.
     *
     * @throws \InvalidArgumentException when $path is outside $base
     */
    public static function assertWithin(string $path, string $base): string
    {
        if (!\str_starts_with($path.'/', \rtrim($base, '/').'/')) {
            throw new \InvalidArgumentException(\sprintf('Path "%s" is outside the allowed base "%s".', $path, $base));
        }

        return $path;
    }

    /**
     * Validates a user-supplied project-relative directory and resolves it inside $base.
     *
     * Accepts only relative paths built from `[A-Za-z0-9/_-]` that do not traverse above
     * their starting point. Returns the target directory with a trailing slash — resolved
     * under $base when given, as-supplied otherwise.
     *
     * Exception messages carry no option/context prefix; callers prepend their own
     * (e.g. `Invalid --dir value "%s": %s`).
     *
     * @throws \InvalidArgumentException when $dir is absolute, uses characters outside the
     *                                   allowlist, traverses upward, or escapes $base
     */
    public static function resolveRelativeDirWithin(string $dir, ?string $base): string
    {
        if (\str_starts_with($dir, '/')) {
            throw new \InvalidArgumentException('must be a relative path.');
        }

        $dir = \rtrim($dir, '/').'/';
        $canonical = self::normalize($dir);

        if (\str_starts_with($canonical, '..') || 1 !== \preg_match('#^[A-Za-z0-9/_\-]+$#', $canonical)) {
            throw new \InvalidArgumentException('must be a relative path using only letters, digits, slash, underscore, dash, and must not traverse above its starting point.');
        }

        if (null === $base) {
            return $dir;
        }

        return self::normalizeWithin($dir, $base).'/';
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
