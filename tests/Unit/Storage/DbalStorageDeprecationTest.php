<?php

declare(strict_types=1);

namespace Soviann\DeployTasksBundle\Tests\Unit\Storage;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Deprecations\Deprecation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorage;
use Soviann\DeployTasksBundle\Storage\Dbal\DbalStorageConfiguration;
use Soviann\DeployTasksBundle\Storage\TaskExecution;
use Soviann\DeployTasksBundle\Storage\TaskStatus;

/**
 * DBAL reports its deprecations through doctrine/deprecations, silent by default,
 * so they never reach PHPUnit's deprecation gate. Tracks them explicitly over a
 * full storage round-trip (editor schema path included) to pin "no DBAL
 * deprecation" on DBAL 4.3+.
 */
#[CoversClass(DbalStorage::class)]
final class DbalStorageDeprecationTest extends TestCase
{
    protected function tearDown(): void
    {
        Deprecation::disable();
    }

    public function testStorageRoundTripTriggersNoDbalDeprecationOnDbal4(): void
    {
        if (!\method_exists(Table::class, 'editor')) {
            self::markTestSkipped('DBAL 3 deprecates APIs that only have DBAL 4 replacements; the guard targets DBAL 4.3+.');
        }

        Deprecation::enableTrackingDeprecations();
        // Counters are process-global and survive disable(): other tests (e.g. the
        // parity test, which runs the DBAL 3 builder on DBAL 4) may already have
        // bumped them, so only this round-trip's delta is asserted.
        $before = Deprecation::getTriggeredDeprecations();

        $storage = new DbalStorage(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            new DbalStorageConfiguration(),
        );
        $storage->getCreateTableSql();
        $storage->createSchema();
        $storage->save(new TaskExecution('t1', TaskStatus::Ran, new \DateTimeImmutable(), 'boom', 'grp', 12));
        $storage->get('t1', 'grp');
        $storage->all();
        $storage->transactional(static fn (): bool => $storage->has('t1', 'grp'));
        $storage->remove('t1', 'grp');

        $triggered = [];

        foreach (Deprecation::getTriggeredDeprecations() as $link => $count) {
            if ($count > ($before[$link] ?? 0)) {
                $triggered[] = $link;
            }
        }

        self::assertSame([], $triggered);
    }
}
