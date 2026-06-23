<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Snapshots;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Snapshots\SnapshotEvent;
use SilverStripe\Versioned\Versioned;
use Sweeper\Tests\Integration\Support\ExposedClearArchiveTask;

/**
 * Runs only under the `snapshots` profile (silverstripe/versioned-snapshots installed).
 *
 * @covers \Sweeper\Tasks\SweeperClearArchiveTask
 */
final class SweeperClearArchiveSnapshotsTest extends SapphireTest
{
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
}
