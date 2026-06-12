<?php

namespace Sweeper\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Sweeper\Schema\SchemaDiff;

/**
 * Pure unit tests for the schema diff. No database required.
 */
class SchemaDiffTest extends TestCase
{
    public function testOrphanTableDetected(): void
    {
        $current = [
            'Page' => ['columns' => ['ID', 'Title'], 'indexes' => []],
            'OldFeature' => ['columns' => ['ID'], 'indexes' => []],
        ];
        $clean = [
            'Page' => ['columns' => ['ID', 'Title'], 'indexes' => []],
        ];

        $diff = SchemaDiff::diff($current, $clean);

        $this->assertSame(['OldFeature'], $diff['tables']);
        $this->assertSame([], $diff['columns']);
        $this->assertSame([], $diff['indexes']);
    }

    public function testOrphanColumnDetected(): void
    {
        $current = [
            'Page' => ['columns' => ['ID', 'Title', 'LegacyField'], 'indexes' => []],
        ];
        $clean = [
            'Page' => ['columns' => ['ID', 'Title'], 'indexes' => []],
        ];

        $diff = SchemaDiff::diff($current, $clean);

        $this->assertSame([], $diff['tables']);
        $this->assertSame(['Page' => ['LegacyField']], $diff['columns']);
    }

    public function testIndexComparedBySignatureNotName(): void
    {
        // Same columns + type, different name: must NOT be flagged as orphaned.
        $current = [
            'Page' => [
                'columns' => ['ID', 'URLSegment'],
                'indexes' => [
                    ['name' => 'URLSegment', 'columns' => ['URLSegment'], 'type' => 'unique'],
                ],
            ],
        ];
        $clean = [
            'Page' => [
                'columns' => ['ID', 'URLSegment'],
                'indexes' => [
                    ['name' => 'Page_URLSegment_xyz', 'columns' => ['URLSegment'], 'type' => 'unique'],
                ],
            ],
        ];

        $diff = SchemaDiff::diff($current, $clean);

        $this->assertSame([], $diff['indexes']);
    }

    public function testOrphanIndexDroppedByItsRealName(): void
    {
        $current = [
            'Page' => [
                'columns' => ['ID', 'OldColumn'],
                'indexes' => [
                    ['name' => 'idx_old_feature', 'columns' => ['OldColumn'], 'type' => 'index'],
                ],
            ],
        ];
        $clean = [
            'Page' => ['columns' => ['ID', 'OldColumn'], 'indexes' => []],
        ];

        $diff = SchemaDiff::diff($current, $clean);

        $this->assertSame(['Page' => ['idx_old_feature']], $diff['indexes']);
    }

    public function testPrimaryKeyNeverDropped(): void
    {
        $current = [
            'Page' => [
                'columns' => ['ID'],
                'indexes' => [
                    ['name' => 'PRIMARY', 'columns' => ['ID'], 'type' => 'unique'],
                ],
            ],
        ];
        // Clean schema has no explicit PRIMARY index spec (it is implicit).
        $clean = [
            'Page' => ['columns' => ['ID'], 'indexes' => []],
        ];

        $diff = SchemaDiff::diff($current, $clean);

        $this->assertSame([], $diff['indexes']);
    }

    public function testFulltextSignaturePreserved(): void
    {
        // A legitimate fulltext index present in both must NOT be dropped...
        $current = [
            'Page' => [
                'columns' => ['ID', 'Title', 'Content'],
                'indexes' => [
                    ['name' => 'SearchFields', 'columns' => ['Title', 'Content'], 'type' => 'fulltext'],
                    ['name' => 'GoneFulltext', 'columns' => ['Legacy'], 'type' => 'fulltext'],
                ],
            ],
        ];
        // ...while an orphaned fulltext index IS detected.
        $clean = [
            'Page' => [
                'columns' => ['ID', 'Title', 'Content'],
                'indexes' => [
                    ['name' => 'whatever', 'columns' => ['Content', 'Title'], 'type' => 'fulltext'],
                ],
            ],
        ];

        $diff = SchemaDiff::diff($current, $clean);

        $this->assertSame(['Page' => ['GoneFulltext']], $diff['indexes']);
    }

    public function testTableMatchingIsCaseInsensitive(): void
    {
        // MySQL with lower_case_table_names=1 returns lowercase table names,
        // while the recorded reference uses the class table-name case. A
        // case-sensitive match would flag every table as orphaned.
        $current = [
            'member' => ['columns' => ['ID', 'Email', 'TwitterAccountName'], 'indexes' => []],
        ];
        $clean = [
            'Member' => ['columns' => ['ID', 'Email'], 'indexes' => []],
        ];

        $diff = SchemaDiff::diff($current, $clean);

        // The table itself must NOT be flagged...
        $this->assertSame([], $diff['tables']);
        // ...but a genuinely orphaned column on it must still be detected.
        $this->assertSame(['member' => ['TwitterAccountName']], $diff['columns']);
    }

    public function testConfirmationTokenIsDeterministicAndOrderIndependent(): void
    {
        // The same droppable set in a different order must yield the same token:
        // dry-run and run=yes recompute the diff independently.
        $a = [
            'tables' => ['old_b', 'old_a'],
            'columns' => ['page' => ['Y', 'X'], 'member' => ['Z']],
            'indexes' => ['page' => ['idx2', 'idx1']],
        ];
        $b = [
            'tables' => ['old_a', 'old_b'],
            'columns' => ['member' => ['Z'], 'page' => ['X', 'Y']],
            'indexes' => ['page' => ['idx1', 'idx2']],
        ];

        $this->assertSame(SchemaDiff::confirmationToken($a), SchemaDiff::confirmationToken($b));
        $this->assertSame(10, strlen(SchemaDiff::confirmationToken($a)));
    }

    public function testConfirmationTokenChangesWhenDroppableSetChanges(): void
    {
        $reviewed = ['tables' => ['old_a'], 'columns' => [], 'indexes' => []];
        $changed = ['tables' => ['old_a', 'old_b'], 'columns' => [], 'indexes' => []];

        $this->assertNotSame(
            SchemaDiff::confirmationToken($reviewed),
            SchemaDiff::confirmationToken($changed)
        );
    }

    public function testIndexSignatureIsColumnOrderIndependent(): void
    {
        $a = SchemaDiff::indexSignature(['Title', 'Content'], 'fulltext');
        $b = SchemaDiff::indexSignature(['Content', 'Title'], 'fulltext');

        $this->assertSame($a, $b);
        $this->assertSame('fulltext:Content,Title', $a);
    }
}
