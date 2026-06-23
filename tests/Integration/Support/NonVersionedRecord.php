<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Support;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A non-versioned DataObject — getBaseVersionedClasses() must exclude it.
 */
class NonVersionedRecord extends DataObject implements TestOnly
{
    private static string $table_name = 'SweeperTest_NonVersioned';

    private static array $db = [
        'Title' => 'Varchar',
    ];
}
