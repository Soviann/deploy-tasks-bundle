<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Identifier;

/**
 * Converts a FQCN to a snake_case task identifier.
 *
 * Strips a leading `DeployTask` or `Task` prefix (only when followed by an
 * uppercase letter or digit, so single words like `Tasking` keep their names)
 * and/or a trailing `DeployTask` or `Task` suffix, then converts the remainder
 * to snake_case. When the stripped remainder is purely numeric (timestamp class
 * produced by `deploytasks:generate:container`), prefixes `task_` so the output
 * matches the recommended `task_<timestamp>` naming.
 *
 * Throws `\InvalidArgumentException` when stripping consumes the entire short name
 * (root-namespace `Task` or `DeployTask`, emptied by the suffix strip). In that
 * case callers must supply an explicit id via `#[AsDeployTask(id: ...)]`.
 *
 * Examples:
 *  - App\Tasks\DeployTask20260416205300  → task_20260416205300
 *  - App\Tasks\Task20260416205300        → task_20260416205300
 *  - App\Tasks\SeedCategoriesTask        → seed_categories
 *  - App\Tasks\SeedCategoriesDeployTask  → seed_categories
 *  - App\Tasks\SeedCategories            → seed_categories
 *  - App\Tasking                         → tasking
 *
 * @internal
 */
final class DefaultTaskIdGenerator implements TaskIdGeneratorInterface
{
    public function generate(string $className): string
    {
        return self::generateStatic($className);
    }

    /**
     * Static twin of {@see generate()} — same derivation, callable without
     * instantiation. RegisterTasksCompilerPass calls it directly for compile-time
     * duplicate-ID detection, so the validated IDs always match the runtime ones.
     */
    public static function generateStatic(string $className): string
    {
        $lastBackslash = \strrpos($className, '\\');
        $shortName = false === $lastBackslash ? $className : \substr($className, $lastBackslash + 1);

        // Strip leading DeployTask / Task prefix only at a CamelCase/digit boundary,
        // so Tasking/Taskmaster keep their names — try longer prefix first
        $shortName = (string) \preg_replace('/^(?:DeployTask|Task)(?=[A-Z0-9])/', '', $shortName);

        // Strip trailing DeployTask / Task suffix — try longer suffix first
        $shortName = (string) \preg_replace('/(?:DeployTask|Task)$/', '', $shortName);

        // Guard: if stripping consumed the entire short name, the class name is ambiguous
        if ('' === $shortName) {
            throw new \InvalidArgumentException(\sprintf('Cannot derive task id from class name "%s"; supply #[AsDeployTask(id: ...)] explicitly.', $className));
        }

        // Purely numeric remainder (timestamp from `deploytasks:generate:container`) → `task_<digits>`
        if (1 === \preg_match('/^\d+$/', $shortName)) {
            return 'task_'.$shortName;
        }

        // CamelCase → snake_case
        $snakeCase = (string) \preg_replace('/[A-Z]/', '_$0', \lcfirst($shortName));

        return \strtolower(\ltrim($snakeCase, '_'));
    }
}
