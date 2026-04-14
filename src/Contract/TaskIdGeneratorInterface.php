<?php

declare(strict_types=1);

namespace Soviann\DeployTasks\Contract;

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
