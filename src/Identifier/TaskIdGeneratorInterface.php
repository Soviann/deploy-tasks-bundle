<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Identifier;

/**
 * Derives a stable task identifier from a task class name.
 *
 * Type-hint seam for the consumers of the built-in generator (TaskIdResolver,
 * DeployTasksGenerateCommand); the sole implementation is DefaultTaskIdGenerator.
 * The returned string may contain any characters; consumers (in particular
 * DeployTasksGenerateCommand) var_export the result before injecting it into
 * generated PHP code, so unusual characters are safe by construction. The
 * generator remains responsible for returning identifiers that are unique and
 * stable across runs.
 */
interface TaskIdGeneratorInterface
{
    /**
     * Generates a canonical task ID from a class name.
     *
     * The class may not exist (yet): deploytasks:generate calls this
     * with the name of the class it is about to create, so implementations must
     * not assume it is loadable.
     *
     * @param string $className Fully-qualified or bare class name
     */
    public function generate(string $className): string;
}
