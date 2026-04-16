<?php

declare(strict_types=1);

namespace Soviann\DeployTasks;

use Soviann\DeployTasks\Contract\Attribute\AsDeployTask;
use Soviann\DeployTasks\Contract\DeployTaskInterface;
use Soviann\DeployTasks\Contract\OrderedTaskCollection;
use Soviann\DeployTasks\Contract\TaskOrderResolverInterface;

/**
 * Resolves the execution order of deploy tasks based on their #[AsDeployTask] attribute.
 *
 * Sorting rules (applied in order):
 *  1. Priority DESC (higher priority runs first)
 *  2. Date extracted from ID (format YYYY_MM_DD) ASC (older tasks run first)
 *  3. Original registration order preserved for ties (stable sort)
 *
 * @internal
 */
final class DefaultTaskOrderResolver implements TaskOrderResolverInterface
{
    public function __construct(
        private readonly TaskIdResolver $idResolver,
    ) {
    }

    /**
     * Sorts the given tasks according to priority, date, and original order.
     *
     * @param array<DeployTaskInterface> $tasks
     */
    public function resolve(array $tasks): OrderedTaskCollection
    {
        /** @var array<int, array{task: DeployTaskInterface, priority: int, date: string|null, index: int}> $indexed */
        $indexed = \array_map(
            function (DeployTaskInterface $task, int $index): array {
                $attribute = AsDeployTask::of($task);

                return [
                    'task' => $task,
                    'priority' => null !== $attribute ? $attribute->priority : 0,
                    'date' => $this->extractDate($this->idResolver->resolve($task)),
                    'index' => $index,
                ];
            },
            $tasks,
            \array_keys($tasks),
        );

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

        return new OrderedTaskCollection(...\array_map(static fn (array $entry): DeployTaskInterface => $entry['task'], $indexed));
    }

    /**
     * Extracts a date string (YYYY_MM_DD) from an ID, or returns null if none found.
     */
    private function extractDate(string $id): ?string
    {
        if (1 === \preg_match('/(\d{4}_\d{2}_\d{2})/', $id, $matches)) {
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
