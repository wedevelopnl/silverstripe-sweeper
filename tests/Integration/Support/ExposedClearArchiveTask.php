<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Support;

use Generator;
use Sweeper\Tasks\SweeperClearArchiveTask;

/**
 * Exposes protected methods of SweeperClearArchiveTask for direct testing.
 */
class ExposedClearArchiveTask extends SweeperClearArchiveTask
{
    public function exposedGetBaseVersionedClasses(): Generator
    {
        return $this->getBaseVersionedClasses();
    }
}
