<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Support;

use RuntimeException;
use SilverStripe\Dev\TestOnly;

/**
 * A versioned record whose snapshot lookup always throws — used to prove
 * flushSnapshots() swallows per-object exceptions and continues. The real method
 * `getRelevantSnapshots()` comes from the snapshots extension via __call; this
 * concrete override shadows it so the call throws.
 */
class ThrowingSnapshotRecord extends VersionedRecord implements TestOnly
{
    private static string $table_name = 'SweeperTest_ThrowingSnapshot';

    public function getRelevantSnapshots()
    {
        throw new RuntimeException('boom');
    }
}
