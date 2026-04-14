<?php

declare(strict_types=1);

namespace Soviann\DeployTasks;

use Soviann\DeployTasks\Contract\TaskIdGeneratorInterface;

/**
 * Converts a FQCN to a snake_case task identifier.
 *
 * Examples:
 *  - App\Tasks\SeedCategoriesTask      → seed_categories
 *  - App\Tasks\SeedCategoriesDeployTask → seed_categories
 *  - App\Tasks\SeedCategories           → seed_categories
 */
final class DefaultTaskIdGenerator implements TaskIdGeneratorInterface
{
    public function generate(string $className): string
    {
        return static::generateStatic($className);
    }

    public static function generateStatic(string $className): string
    {
        $lastBackslash = \strrpos($className, '\\');
        $shortName = false === $lastBackslash ? $className : \substr($className, $lastBackslash + 1);

        // Remove common suffixes — try longer suffix first
        $shortName = (string) \preg_replace('/(?:DeployTask|Task)$/', '', $shortName);

        // If nothing left after stripping, fall back to original short name
        if ('' === $shortName) {
            $shortName = false === $lastBackslash ? $className : \substr($className, $lastBackslash + 1);
        }

        // CamelCase → snake_case
        /** @var string $snakeCase */
        $snakeCase = \preg_replace('/[A-Z]/', '_$0', \lcfirst($shortName));

        return \strtolower($snakeCase);
    }
}
