<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Support;

use SilverStripe\ORM\DataExtension;

/**
 * A DataExtension applied to nothing — the report must list it as never applied.
 */
class NeverAppliedExtension extends DataExtension
{
}
