<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Other;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Lives outside the Support namespace so the namespace-filter test has a control
 * whose FQCN does not contain "Support".
 */
class NonMatchingNamespaceObject extends DataObject implements TestOnly
{
    private static string $table_name = 'SweeperTest_OtherNs';

    private static array $db = [
        'Title' => 'Varchar',
    ];
}
