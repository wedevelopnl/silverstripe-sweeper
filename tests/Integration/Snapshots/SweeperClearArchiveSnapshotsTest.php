<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Snapshots;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Snapshots\SnapshotEvent;
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
        $task = ExposedClearArchiveTask::create();
        $classes = iterator_to_array($task->exposedGetBaseVersionedClasses());

        self::assertNotContains(SnapshotEvent::class, $classes);
    }
}
