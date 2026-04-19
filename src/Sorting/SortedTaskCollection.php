<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Sorting;

use Soviann\DeployTasksBundle\DeployTaskInterface;

/**
 * Type-safe, immutable collection of deploy tasks in sorted execution order.
 *
 * The variadic constructor guarantees that only DeployTaskInterface instances
 * can be stored — enforcing the contract at runtime, not just via PHPDoc.
 *
 * @implements \IteratorAggregate<int, DeployTaskInterface>
 */
final readonly class SortedTaskCollection implements \IteratorAggregate, \Countable
{
    /** @var list<DeployTaskInterface> */
    private array $tasks;

    public function __construct(DeployTaskInterface ...$tasks)
    {
        $this->tasks = \array_values($tasks);
    }

    /**
     * @return \ArrayIterator<int, DeployTaskInterface>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->tasks);
    }

    public function count(): int
    {
        return \count($this->tasks);
    }

    /**
     * Whether the collection contains no tasks.
     */
    public function isEmpty(): bool
    {
        return [] === $this->tasks;
    }

    /**
     * Returns the tasks as a plain array.
     *
     * @return list<DeployTaskInterface>
     */
    public function toArray(): array
    {
        return $this->tasks;
    }
}
