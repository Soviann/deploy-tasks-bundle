<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Identifier;

/**
 * Converts a FQCN to a snake_case task identifier.
 *
 * Strips a leading `DeployTask` or `Task` prefix and/or a trailing `DeployTask`
 * or `Task` suffix, then converts the remainder to snake_case.
 *
 * Examples:
 *  - App\Tasks\DeployTask20260416205300  → 20260416205300
 *  - App\Tasks\SeedCategoriesTask        → seed_categories
 *  - App\Tasks\SeedCategoriesDeployTask  → seed_categories
 *  - App\Tasks\SeedCategories            → seed_categories
 *
 * @internal
 */
final class DefaultTaskIdGenerator implements TaskIdGeneratorInterface
{
    public function generate(string $className): string
    {
        return self::generateStatic($className);
    }

    public static function generateStatic(string $className): string
    {
        $lastBackslash = \strrpos($className, '\\');
        $shortName = false === $lastBackslash ? $className : \substr($className, $lastBackslash + 1);
        $original = $shortName;

        // Strip leading DeployTask / Task prefix — try longer prefix first
        $shortName = (string) \preg_replace('/^(?:DeployTask|Task)/', '', $shortName);

        // Strip trailing DeployTask / Task suffix — try longer suffix first
        $shortName = (string) \preg_replace('/(?:DeployTask|Task)$/', '', $shortName);

        // If nothing left after stripping, fall back to original short name
        if ('' === $shortName) {
            $shortName = $original;
        }

        // CamelCase → snake_case
        /** @var string $snakeCase */
        $snakeCase = \preg_replace('/[A-Z]/', '_$0', \lcfirst($shortName));

        return \strtolower(\ltrim($snakeCase, '_'));
    }
}
