<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Snapshots;

use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Snapshots\ActivityEntry;
use SilverStripe\Snapshots\Snapshot;
use SilverStripe\Snapshots\SnapshotEvent;
use SilverStripe\Snapshots\SnapshotItem;
use SilverStripe\Versioned\Versioned;
use Sweeper\Tasks\SweeperClearArchiveTask;
use Sweeper\Tests\Integration\Support\ExposedClearArchiveTask;
use Sweeper\Tests\Integration\Support\ThrowingSnapshotRecord;
use Sweeper\Tests\Integration\Support\VersionedRecord;

/**
 * Runs only under the `snapshots` profile (silverstripe/versioned-snapshots installed).
 *
 * @covers \Sweeper\Tasks\SweeperClearArchiveTask
 */
final class SweeperClearArchiveSnapshotsTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        VersionedRecord::class,
        ThrowingSnapshotRecord::class,
    ];

    public function testBaseVersionedClassesExcludesSnapshotEventWhenInstalled(): void
    {
        // SnapshotEvent must actually be a candidate (direct DataObject subclass + Versioned),
        // otherwise its absence below wouldn't prove the exclusion guard did anything.
        self::assertSame(DataObject::class, get_parent_class(SnapshotEvent::class));
        self::assertTrue(DataObject::has_extension(SnapshotEvent::class, Versioned::class));

        $task = ExposedClearArchiveTask::create();
        $classes = iterator_to_array($task->exposedGetBaseVersionedClasses());

        self::assertNotContains(SnapshotEvent::class, $classes);
    }

    public function testFlushSnapshotsDryReportsCountsAndDeletesNothing(): void
    {
        Config::modify()->set(SweeperClearArchiveTask::class, 'keep', 2);
        $record = $this->makeSnapshots(5);
        $before = $this->fullVersionCount($record);
        self::assertGreaterThan(2, $before);

        $task = SweeperClearArchiveTask::create();
        $task->setDry(true);

        $output = $this->discardOutput(static fn () => $task->flushSnapshots(VersionedRecord::class));

        self::assertStringContainsString('(dry-run)', $output);
        self::assertStringContainsString('Cleared', $output);
        self::assertSame($before, $this->fullVersionCount($record));
    }

    public function testFlushSnapshotsRealKeepsExactlyKeptCount(): void
    {
        Config::modify()->set(SweeperClearArchiveTask::class, 'keep', 2);
        $record = $this->makeSnapshots(5);
        self::assertGreaterThan(2, $this->fullVersionCount($record));

        $task = SweeperClearArchiveTask::create();
        $task->setDry(false);

        $this->discardOutput(static fn () => $task->flushSnapshots(VersionedRecord::class));

        self::assertSame(2, $this->fullVersionCount($record));
    }

    public function testFlushSnapshotsSwallowsPerObjectExceptions(): void
    {
        $record = ThrowingSnapshotRecord::create();
        $record->Title = 'boom';
        $record->write();

        $task = SweeperClearArchiveTask::create();
        $task->setDry(false);

        // getRelevantSnapshots() throws; flushSnapshots() must catch, log, and continue.
        $output = $this->discardOutput(
            static fn () => $task->flushSnapshots(ThrowingSnapshotRecord::class),
        );

        self::assertStringContainsString('Exception during parsing of object', $output);
    }

    /**
     * Create a VersionedRecord carrying $n full-version snapshots.
     *
     * Discovery (Step 1): bare write() and publishSingle() produce 0 full-version
     * snapshots in the SapphireTest context because versioned-snapshots creates
     * Snapshot records only via its event-handler system (not via ORM hooks).
     * The reliable recipe is to write the record once to obtain an ID, then
     * directly insert Snapshot + SnapshotItem rows using the ORM.
     */
    private function makeSnapshots(int $n): VersionedRecord
    {
        $record = VersionedRecord::create();
        $record->Title = 'initial';
        $record->write();

        $objectHash = Snapshot::singleton()->hashObjectForSnapshot($record);

        for ($i = 1; $i <= $n; $i++) {
            $snapshot = Snapshot::create();
            $snapshot->OriginClass = $record->baseClass();
            $snapshot->OriginID = $record->ID;
            $snapshot->OriginHash = $objectHash;
            $snapshot->write();

            $item = SnapshotItem::create();
            $item->ObjectClass = $record->baseClass();
            $item->ObjectID = $record->ID;
            $item->ObjectVersion = $record->Version;
            $item->ObjectHash = $objectHash;
            $item->SnapshotID = $snapshot->ID;
            $item->WasDraft = true;
            $item->WasCreated = ($i === 1);
            $item->write();
        }

        return $record;
    }

    private function fullVersionCount(VersionedRecord $record): int
    {
        $objectHash = Snapshot::singleton()->hashObjectForSnapshot($record);
        $count = 0;
        foreach ($record->getRelevantSnapshots() as $snapshot) {
            if (
                $snapshot->OriginHash === $objectHash
                && $snapshot->getActivityType() !== ActivityEntry::DELETED
            ) {
                $count++;
            }
        }

        return $count;
    }

    private function discardOutput(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $output = ob_get_clean();
        }

        return (string) $output;
    }
}
