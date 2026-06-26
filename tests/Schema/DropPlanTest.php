<?php

namespace Sweeper\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Sweeper\Schema\DropPlan;

/**
 * Pure unit tests for the drop plan ordering. No database required.
 */
class DropPlanTest extends TestCase
{
    public function testDropsIndexesBeforeColumnsOnTheSameTable(): void
    {
        // A table carrying both an orphan column and an orphan index on it.
        // Dropping the column first makes MySQL implicitly drop the index, so a
        // later explicit DROP INDEX fails with error 1091. The index must go first.
        $droppable = [
            'tables' => [],
            'columns' => ['Page' => ['LegacyField']],
            'indexes' => ['Page' => ['idx_legacy']],
        ];

        $statements = DropPlan::statements($droppable);

        $indexAt = $this->firstIndexContaining($statements, 'DROP INDEX');
        $columnAt = $this->firstIndexContaining($statements, 'DROP COLUMN');

        $this->assertNotNull($indexAt, 'expected a DROP INDEX statement');
        $this->assertNotNull($columnAt, 'expected a DROP COLUMN statement');
        $this->assertLessThan($columnAt, $indexAt, 'DROP INDEX must precede DROP COLUMN');
    }

    public function testEmitsTablesFirstThenIndexesThenColumns(): void
    {
        $droppable = [
            'tables' => ['OldFeature'],
            'columns' => ['Page' => ['LegacyField']],
            'indexes' => ['Page' => ['idx_legacy']],
        ];

        $statements = DropPlan::statements($droppable);

        $this->assertSame([
            'DROP TABLE IF EXISTS "OldFeature"',
            'DROP INDEX "idx_legacy" ON "Page"',
            'ALTER TABLE "Page" DROP COLUMN "LegacyField"',
        ], $statements);
    }

    public function testNeverEmitsDropForThePrimaryKey(): void
    {
        // Defence in depth: diff() already excludes PRIMARY, but the plan must
        // never emit a DROP INDEX "PRIMARY" even if one slips into the set.
        $droppable = [
            'tables' => [],
            'columns' => [],
            'indexes' => ['Page' => ['PRIMARY', 'idx_legacy']],
        ];

        $statements = DropPlan::statements($droppable);

        $this->assertSame(['DROP INDEX "idx_legacy" ON "Page"'], $statements);
    }

    private function firstIndexContaining(array $statements, string $needle): ?int
    {
        foreach ($statements as $i => $sql) {
            if (str_contains($sql, $needle)) {
                return $i;
            }
        }

        return null;
    }
}
