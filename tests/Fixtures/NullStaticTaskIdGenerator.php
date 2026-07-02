<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Soviann\DeployTasksBundle\Identifier\TaskIdGeneratorInterface;

/**
 * Generator whose static variant always returns null, opting every task without
 * an explicit attribute ID out of compile-time duplicate detection — the runtime
 * generate() remains the only ID source.
 */
final class NullStaticTaskIdGenerator implements TaskIdGeneratorInterface
{
    public function generate(string $className): string
    {
        return 'runtime_'.\hash('crc32b', $className);
    }

    public static function generateStatic(string $className): ?string
    {
        return null;
    }
}
