<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Support;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A DataObject that gets an instance in tests, and carries AppliedExtension so
 * the report sees that extension as applied.
 */
class ReportPopulatedObject extends DataObject implements TestOnly
{
    private static string $table_name = 'SweeperTest_ReportPopulated';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $extensions = [
        AppliedExtension::class,
    ];
}
