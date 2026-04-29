<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Identifier;

/**
 * Derives a stable task identifier from a task class name.
 *
 * Implementations may return any string; consumers (in particular
 * DeployTasksGenerateCommand) var_export the result before injecting it
 * into generated PHP code, so unusual characters are safe by construction.
 * Implementations are still responsible for returning identifiers that are
 * unique and stable across runs.
 *
 * Implementations are also used at compile time via {@see generateStatic()} for
 * duplicate-ID detection. Returning null from the static variant opts a task out of
 * compile-time detection — duplicates will then surface at runtime in the registry.
 */
interface TaskIdGeneratorInterface
{
    /**
     * Generates a canonical task ID from a fully-qualified class name.
     * Called at runtime; may use injected services.
     *
     * @param class-string $className
     */
    public function generate(string $className): string;

    /**
     * Compile-time variant: returns the ID if it can be determined statically,
     * or null if runtime context (e.g. injected services) is required.
     * Returning null opts this task out of compile-time duplicate detection.
     *
     * @param class-string $className
     */
    public static function generateStatic(string $className): ?string;
}
