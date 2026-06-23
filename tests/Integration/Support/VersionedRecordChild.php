<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Support;

use SilverStripe\Dev\TestOnly;

/**
 * A subclass of VersionedRecord — creates a separate subclass _Versions table
 * for the orphaned-subclass-version path, and is NOT a direct DataObject
 * subclass (so it is excluded from getBaseVersionedClasses()).
 */
class VersionedRecordChild extends VersionedRecord implements TestOnly
{
    private static string $table_name = 'SweeperTest_VersionedChild';

    private static array $db = [
        'Subtitle' => 'Varchar',
    ];
}
