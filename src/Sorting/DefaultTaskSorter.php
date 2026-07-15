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
 *  2. Timestamp extracted from ID (14 digits YYYYMMDDHHIISS, or 8 digits YYYYMMDD) ASC
 *     (older tasks run first)
 *  3. Original registration order preserved for ties (usort() is stable since PHP 8.0)
 *
 * @internal
 */
final readonly class DefaultTaskSorter implements TaskSorterInterface
{
    public function __construct(
        private TaskIdResolver $idResolver,
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
        /** @var list<array{task: DeployTaskInterface, priority: int, date: string|null}> $indexed */
        $indexed = [];

        foreach ($tasks as $task) {
            $attribute = AsDeployTask::of($task);

            $indexed[] = [
                'task' => $task,
                'priority' => null !== $attribute ? $attribute->priority : 0,
                'date' => $this->extractDate($this->idResolver->resolve($task)),
            ];
        }

        \usort($indexed, function (array $a, array $b): int {
            // 1. Priority DESC
            $priorityDiff = $b['priority'] <=> $a['priority'];
            if (0 !== $priorityDiff) {
                return $priorityDiff;
            }

            // 2. Date ASC (tasks without date sort after tasks with date).
            // Ties keep registration order — usort() is stable since PHP 8.0.
            return $this->compareDates($a['date'], $b['date']);
        });

        return \array_map(static fn (array $entry): DeployTaskInterface => $entry['task'], $indexed);
    }

    /**
     * Extracts the longest leading timestamp digit run from an ID — 14 digits
     * (YYYYMMDDHHIISS) preferred over 8 (YYYYMMDD) — or returns null if none found.
     *
     * Extracts are compared byte-wise (see compareDates()), which is chronologically
     * correct across mixed lengths because both formats start with the same fixed-width
     * YYYYMMDD prefix: the first differing byte decides per calendar position, and when
     * one extract is a strict prefix of the other (a date-only id vs a same-day
     * timestamp), the shorter one sorts first ('20260416' < '20260416090000').
     */
    private function extractDate(string $id): ?string
    {
        if (1 === \preg_match('/(\d{14}|\d{8})/', $id, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Compares two nullable date strings for ascending sort.
     * Tasks without a date sort after tasks with a date.
     *
     * The comparison must be byte-wise (strcmp), not `<=>`: PHP's spaceship operator
     * compares two numeric strings NUMERICALLY, which inverts chronology for mixed-length
     * extracts (numerically 20260417 < 20260416235959, yet Apr 17 is the later day).
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

        return \strcmp($a, $b) <=> 0;
    }
}
