<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Shared filesystem helpers for tests: disposable temp directories,
 * recursive cleanup that survives read-restricted subdirectories,
 * and a POSIX-permissions assertion wrapper.
 */
final class FilesystemTestHelper
{
    /**
     * Create a unique empty directory under sys_get_temp_dir() with owner-only permissions.
     */
    public static function tempDir(string $prefix = 'deploy-tasks-'): string
    {
        $path = \sys_get_temp_dir().'/'.$prefix.\uniqid('', true);
        \mkdir($path, 0o700, true);

        return $path;
    }

    /**
     * Recursively delete a directory. No-op if it does not exist.
     * Restores 0700 on any unreadable subdirectory before removal.
     */
    public static function cleanup(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $pathname = $entry->getPathname();

            if ($entry->isDir()) {
                if (!$entry->isReadable()) {
                    \chmod($pathname, 0o700);
                }
                \rmdir($pathname);
            } else {
                \unlink($pathname);
            }
        }

        \rmdir($path);
    }

    /**
     * Assert POSIX file-mode bits (masked to 0777) on a path.
     */
    public static function assertPermissions(string $path, int $expected): void
    {
        $actual = \fileperms($path) & 0o777;
        Assert::assertSame(
            $expected,
            $actual,
            \sprintf('Expected permissions 0%o on "%s", got 0%o.', $expected, $path, $actual),
        );
    }
}
