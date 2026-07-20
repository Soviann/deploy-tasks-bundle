<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Fixtures;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Lock factory whose locks acquire normally but always fail their between-task
 * refresh — simulating a lease that expired mid-run (e.g. a task outrunning
 * lock.ttl) without any sleeping or real TTL.
 */
final class LeaseLosingLockFactory extends LockFactory
{
    public function __construct()
    {
        parent::__construct(new InMemoryStore());
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        $inner = parent::createLock($resource, $ttl, $autoRelease);

        return new class($inner) implements SharedLockInterface {
            public function __construct(private readonly SharedLockInterface $inner)
            {
            }

            public function acquire(bool $blocking = false): bool
            {
                return $this->inner->acquire($blocking);
            }

            public function acquireRead(bool $blocking = false): bool
            {
                return $this->inner->acquireRead($blocking);
            }

            public function refresh(?float $ttl = null): void
            {
                throw new LockConflictedException('lease lost (test fixture)');
            }

            public function isAcquired(): bool
            {
                return $this->inner->isAcquired();
            }

            public function release(): void
            {
                $this->inner->release();
            }

            public function isExpired(): bool
            {
                return $this->inner->isExpired();
            }

            public function getRemainingLifetime(): ?float
            {
                return $this->inner->getRemainingLifetime();
            }
        };
    }
}
