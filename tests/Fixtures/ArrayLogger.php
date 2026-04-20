<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Psr\Log\AbstractLogger;

/**
 * Tiny PSR-3 spy reused by unit and functional tests to assert on emitted records.
 *
 * `Psr\Log\Test\TestLogger` is gone in psr/log v3 — the bundle targets v1..v3, so we ship
 * our own minimal capture.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        // PSR-3 callers (including LoggerTrait used by AbstractLogger) always pass a string
        // level — assert so the stored shape stays string-typed for downstream assertions.
        \assert(\is_string($level));

        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function has(string $level, string $messageSubstring): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && \str_contains($record['message'], $messageSubstring)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public function recordsMatching(string $level, string $messageSubstring): array
    {
        $matches = [];

        foreach ($this->records as $record) {
            if ($record['level'] === $level && \str_contains($record['message'], $messageSubstring)) {
                $matches[] = $record;
            }
        }

        return $matches;
    }
}
