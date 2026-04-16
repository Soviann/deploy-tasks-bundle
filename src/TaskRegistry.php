<?php

declare(strict_types=1);

namespace Soviann\DeployTasks;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Exception\DuplicateTaskIdException;
use Soviann\DeployTasks\Exception\TaskNotFoundException;

/**
 * In-memory registry of all discovered deploy tasks, keyed by ID.
 *
 * @internal
 */
final class TaskRegistry
{
    /** @var array<string, DeployTaskInterface> */
    private array $tasks = [];

    /**
     * @param iterable<DeployTaskInterface> $tasks      Tagged deploy task services
     * @param TaskIdResolver                $idResolver Resolves the canonical ID for each task
     */
    public function __construct(iterable $tasks, TaskIdResolver $idResolver)
    {
        foreach ($tasks as $task) {
            $id = $idResolver->resolve($task);

            if (isset($this->tasks[$id])) {
                throw DuplicateTaskIdException::create($id);
            }

            $this->tasks[$id] = $task;
        }
    }

    /**
     * Returns registered tasks, keyed by ID. When an environment is given,
     * only tasks that match that environment (or have no env restriction) are returned.
     *
     * @return array<string, DeployTaskInterface>
     */
    public function all(?string $environment = null): array
    {
        if (null === $environment) {
            return $this->tasks;
        }

        return \array_filter($this->tasks, static function (DeployTaskInterface $task) use ($environment): bool {
            $attribute = AsDeployTask::of($task);

            if (null === $attribute || null === $attribute->env) {
                return true;
            }

            $envs = \is_array($attribute->env) ? $attribute->env : [$attribute->env];

            return \in_array($environment, $envs, true);
        });
    }

    /**
     * Returns the task with the given ID.
     *
     * @throws TaskNotFoundException If no task is registered with this ID
     */
    public function get(string $id): DeployTaskInterface
    {
        if (!isset($this->tasks[$id])) {
            throw TaskNotFoundException::create($id);
        }

        return $this->tasks[$id];
    }

    /**
     * Whether a task with the given ID is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->tasks[$id]);
    }
}
