<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Support;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * A versioned DataObject that is a DIRECT subclass of DataObject — so
 * getBaseVersionedClasses() yields it as a base versioned class.
 */
class VersionedRecord extends DataObject implements TestOnly
{
    private static string $table_name = 'SweeperTest_Versioned';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $extensions = [
        Versioned::class,
    ];
}
