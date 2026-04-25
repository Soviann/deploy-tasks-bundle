<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Sorting;

use Soviann\DeployTasksBundle\Attribute\AsDeployTask;
use Soviann\DeployTasksBundle\DeployTaskInterface;
use Soviann\DeployTasksBundle\Identifier\TaskIdResolver;

/**
 * Default sort for deploy tasks based on their #[AsDeployTask] attribute.
 *
 * Sorting rules (applied in order):
 *  1. Priority DESC (higher priority runs first)
 *  2. Date extracted from ID (8 consecutive digits, YYYYMMDD) ASC (older tasks run first)
 *  3. Original registration order preserved for ties (stable sort)
 *
 * @internal
 */
final class DefaultTaskSorter implements TaskSorterInterface
{
    public function __construct(
        private readonly TaskIdResolver $idResolver,
    ) {
    }

    /**
     * Sorts the given tasks according to priority, date, and original order.
     *
     * @param array<DeployTaskInterface> $tasks
     *
     * @return list<DeployTaskInterface>
     *
     * @throws \ReflectionException When the #[AsDeployTask] attribute lookup fails for a task
     */
    public function sort(array $tasks): array
    {
        /** @var list<array{task: DeployTaskInterface, priority: int, date: string|null, index: int}> $indexed */
        $indexed = [];

        foreach ($tasks as $index => $task) {
            $attribute = AsDeployTask::of($task);

            $indexed[] = [
                'task' => $task,
                'priority' => null !== $attribute ? $attribute->priority : 0,
                'date' => $this->extractDate($this->idResolver->resolve($task)),
                'index' => $index,
            ];
        }

        \usort($indexed, function (array $a, array $b): int {
            // 1. Priority DESC
            $priorityDiff = $b['priority'] <=> $a['priority'];
            if (0 !== $priorityDiff) {
                return $priorityDiff;
            }

            // 2. Date ASC (tasks without date sort after tasks with date)
            $dateCompare = $this->compareDates($a['date'], $b['date']);
            if (0 !== $dateCompare) {
                return $dateCompare;
            }

            // 3. Original order (stable sort)
            return $a['index'] <=> $b['index'];
        });

        return \array_map(static fn (array $entry): DeployTaskInterface => $entry['task'], $indexed);
    }

    /**
     * Extracts a date string (8 consecutive digits, YYYYMMDD) from an ID, or returns null if none found.
     */
    private function extractDate(string $id): ?string
    {
        if (1 === \preg_match('/(\d{8})/', $id, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Compares two nullable date strings for ascending sort.
     * Tasks without a date sort after tasks with a date.
     */
    private function compareDates(?string $a, ?string $b): int
    {
        if (null === $a && null === $b) {
            return 0;
        }

        if (null === $a) {
            return 1;
        }

        if (null === $b) {
            return -1;
        }

        return $a <=> $b;
    }
}
