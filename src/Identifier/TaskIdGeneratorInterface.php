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
 *
 * Contract between the two variants: when generateStatic() returns non-null, it MUST
 * equal what generate() returns for the same class name — otherwise compile-time
 * duplicate detection validates an ID that never exists at runtime.
 */
interface TaskIdGeneratorInterface
{
    /**
     * Generates a canonical task ID from a class name.
     *
     * Called at runtime; may use injected services. The class may not exist (yet):
     * deploytasks:generate:container calls this with the name of the class it is
     * about to create, so implementations must not assume it is loadable.
     *
     * @param string $className Fully-qualified or bare class name
     */
    public function generate(string $className): string;

    /**
     * Compile-time variant: returns the ID if it can be determined statically,
     * or null if runtime context (e.g. injected services) is required.
     * Returning null opts this task out of compile-time duplicate detection.
     *
     * Must agree with {@see generate()} whenever it returns non-null.
     *
     * @param string $className Fully-qualified or bare class name; may not be loadable
     */
    public static function generateStatic(string $className): ?string;
}
