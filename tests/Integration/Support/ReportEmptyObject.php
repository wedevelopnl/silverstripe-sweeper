<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Support;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A DataObject deliberately never instantiated — the report must list it as
 * having no active instances.
 */
class ReportEmptyObject extends DataObject implements TestOnly
{
    private static string $table_name = 'SweeperTest_ReportEmpty';

    private static array $db = [
        'Title' => 'Varchar',
    ];
}
