<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Support;

use SilverStripe\ORM\DataExtension;

/**
 * A DataExtension applied to ReportPopulatedObject — the report must treat it
 * as "applied" and exclude it from the never-applied list.
 */
class AppliedExtension extends DataExtension
{
}
