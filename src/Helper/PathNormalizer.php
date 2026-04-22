<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Helper;

use Symfony\Component\Filesystem\Path;

/**
 * @internal
 */
final class PathNormalizer
{
    public static function normalize(string $path): string
    {
        return Path::canonicalize(\rtrim($path, '/'));
    }
}
