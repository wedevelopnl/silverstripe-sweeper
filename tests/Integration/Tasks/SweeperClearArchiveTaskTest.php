<?php

declare(strict_types=1);

namespace Sweeper\Tests\Integration\Tasks;

use InvalidArgumentException;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Sweeper\Tasks\SweeperClearArchiveTask;
use Sweeper\Tests\Integration\Support\ExposedClearArchiveTask;
use Sweeper\Tests\Integration\Support\NonVersionedRecord;
use Sweeper\Tests\Integration\Support\VersionedRecord;
use Sweeper\Tests\Integration\Support\VersionedRecordChild;

/**
 * @covers \Sweeper\Tasks\SweeperClearArchiveTask
 */
final class SweeperClearArchiveTaskTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        VersionedRecord::class,
        VersionedRecordChild::class,
        NonVersionedRecord::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    /**
     * Write $n versions of a fresh VersionedRecord (changing Title each write so
     * every write produces a new version). Returns the record.
     */
    private function makeVersions(int $n): VersionedRecord
    {
        $record = VersionedRecord::create();
        for ($i = 1; $i <= $n; $i++) {
            $record->Title = 'v' . $i;
            $record->write();
        }

        return $record;
    }

    private function versionCount(string $class, int $recordId): int
    {
        $table = DataObject::getSchema()->tableName($class) . '_Versions';

        return (int) DB::prepared_query(
            "SELECT COUNT(*) FROM \"{$table}\" WHERE \"RecordID\" = ?",
            [$recordId],
        )->value();
    }

    private function discardOutput(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $output = ob_get_clean();
        }

        return (string) $output;
    }

    // ── run() orchestration ──────────────────────────────────────

    public function testRunWithInvalidArgumentThrows(): void
    {
        $task = SweeperClearArchiveTask::create();
        $request = new HTTPRequest('GET', '', ['run' => 'nonsense']);

        $this->expectException(InvalidArgumentException::class);

        $task->run($request);
    }

    public function testRunDryCompletes(): void
    {
        $task = SweeperClearArchiveTask::create();
        $request = new HTTPRequest('GET', '', ['run' => 'dry']);

        $output = $this->discardOutput(static fn () => $task->run($request));

        self::assertStringContainsString('Flush complete!', $output);
    }

    // ── getKeepVersions() precedence ─────────────────────────────

    /**
     * config('keep') ?: instance. config.yml sets keep:10, so config wins unless
     * overridden to a falsy value.
     *
     * @dataProvider keepVersionCases
     */
    public function testGetKeepVersionsPrecedence(?int $configKeep, int $setKeep, int $expected): void
    {
        if ($configKeep !== null) {
            Config::modify()->set(SweeperClearArchiveTask::class, 'keep', $configKeep);
        }

        $task = SweeperClearArchiveTask::create();
        $task->setKeepVersions($setKeep);

        self::assertSame($expected, $task->getKeepVersions());
    }

    /**
     * @return array<string, array{0: int|null, 1: int, 2: int}>
     */
    public static function keepVersionCases(): array
    {
        return [
            'config truthy wins over setter' => [5, 3, 5],
            'config zero falls back to setter' => [0, 3, 3],
            'config default 10 overrides setter' => [null, 3, 10],
        ];
    }

    // ── deleteOldVersions() — draft records ──────────────────────

    public function testDeleteOldVersionsDryReportsCountAndKeepsRows(): void
    {
        Config::modify()->set(SweeperClearArchiveTask::class, 'keep', 2);
        $record = $this->makeVersions(5);
        $id = (int) $record->ID;

        $task = SweeperClearArchiveTask::create();
        $task->setDry(true);

        $output = $this->discardOutput(static fn () => $task->deleteOldVersions(VersionedRecord::class));

        self::assertStringContainsString('Cleared 3 old versions', $output);
        self::assertSame(5, $this->versionCount(VersionedRecord::class, $id));
    }

    public function testDeleteOldVersionsRealKeepsExactlyKeptCount(): void
    {
        Config::modify()->set(SweeperClearArchiveTask::class, 'keep', 2);
        $record = $this->makeVersions(5);
        $id = (int) $record->ID;

        $task = SweeperClearArchiveTask::create();
        $task->setDry(false);

        $this->discardOutput(static fn () => $task->deleteOldVersions(VersionedRecord::class));

        self::assertSame(2, $this->versionCount(VersionedRecord::class, $id));
    }

    // ── deleteArchivedVersionsWithVersionRetention() — deleted records ──

    public function testRetentionDryReportsCountAndKeepsRows(): void
    {
        Config::modify()->set(SweeperClearArchiveTask::class, 'keep', 2);
        $record = $this->makeVersions(5);
        $id = (int) $record->ID;
        $record->delete();
        $before = $this->versionCount(VersionedRecord::class, $id);
        self::assertGreaterThanOrEqual(5, $before);

        $task = SweeperClearArchiveTask::create();
        $task->setDry(true);

        $output = $this->discardOutput(
            static fn () => $task->deleteArchivedVersionsWithVersionRetention(VersionedRecord::class),
        );

        self::assertStringContainsString('old archived versions', $output);
        self::assertSame($before, $this->versionCount(VersionedRecord::class, $id));
    }

    public function testRetentionRealKeepsExactlyKeptCount(): void
    {
        Config::modify()->set(SweeperClearArchiveTask::class, 'keep', 2);
        $record = $this->makeVersions(5);
        $id = (int) $record->ID;
        $record->delete();

        $task = SweeperClearArchiveTask::create();
        $task->setDry(false);

        $this->discardOutput(
            static fn () => $task->deleteArchivedVersionsWithVersionRetention(VersionedRecord::class),
        );

        self::assertSame(2, $this->versionCount(VersionedRecord::class, $id));
    }

    // ── deleteArchivedVersions() — rows orphaned by base-record deletion ──

    public function testDeleteArchivedVersionsDryReportsCountAndKeepsRows(): void
    {
        $record = $this->makeVersions(3);
        $id = (int) $record->ID;
        $record->delete();
        $before = $this->versionCount(VersionedRecord::class, $id);

        $task = SweeperClearArchiveTask::create();
        $task->setDry(true);

        $output = $this->discardOutput(static fn () => $task->deleteArchivedVersions(VersionedRecord::class));

        self::assertStringContainsString('for deleted records', $output);
        self::assertSame($before, $this->versionCount(VersionedRecord::class, $id));
    }

    public function testDeleteArchivedVersionsRealRemovesOrphanedRows(): void
    {
        $record = $this->makeVersions(3);
        $id = (int) $record->ID;
        $record->delete();
        self::assertGreaterThanOrEqual(3, $this->versionCount(VersionedRecord::class, $id));

        $task = SweeperClearArchiveTask::create();
        $task->setDry(false);

        $this->discardOutput(static fn () => $task->deleteArchivedVersions(VersionedRecord::class));

        self::assertSame(0, $this->versionCount(VersionedRecord::class, $id));
    }

    // ── deleteOrphanedVersions() — subclass rows with no base version ──

    private function makeChildVersions(int $n): VersionedRecordChild
    {
        $child = VersionedRecordChild::create();
        for ($i = 1; $i <= $n; $i++) {
            $child->Title = 'c' . $i;
            $child->Subtitle = 's' . $i;
            $child->write();
        }

        return $child;
    }

    private function childVersionCount(int $recordId): int
    {
        $childTable = DataObject::getSchema()->tableName(VersionedRecordChild::class) . '_Versions';

        return (int) DB::prepared_query(
            "SELECT COUNT(*) FROM \"{$childTable}\" WHERE \"RecordID\" = ?",
            [$recordId],
        )->value();
    }

    public function testDeleteOrphanedVersionsDryReportsCountAndKeepsRows(): void
    {
        $child = $this->makeChildVersions(2);
        $id = (int) $child->ID;
        $baseTable = DataObject::getSchema()->tableName(VersionedRecord::class) . '_Versions';
        DB::prepared_query(
            "DELETE FROM \"{$baseTable}\" WHERE \"RecordID\" = ? AND \"Version\" = ?",
            [$id, 1],
        );
        $before = $this->childVersionCount($id);

        $task = SweeperClearArchiveTask::create();
        $task->setDry(true);

        $this->discardOutput(static fn () => $task->deleteOrphanedVersions(VersionedRecord::class));

        self::assertSame($before, $this->childVersionCount($id));
    }

    public function testDeleteOrphanedVersionsRealRemovesOrphanedSubclassRows(): void
    {
        $child = $this->makeChildVersions(2);
        $id = (int) $child->ID;
        $baseTable = DataObject::getSchema()->tableName(VersionedRecord::class) . '_Versions';
        DB::prepared_query(
            "DELETE FROM \"{$baseTable}\" WHERE \"RecordID\" = ? AND \"Version\" = ?",
            [$id, 1],
        );
        $before = $this->childVersionCount($id);

        $task = SweeperClearArchiveTask::create();
        $task->setDry(false);

        $this->discardOutput(static fn () => $task->deleteOrphanedVersions(VersionedRecord::class));

        self::assertSame($before - 1, $this->childVersionCount($id));
    }

    // ── flushClass() orchestration ───────────────────────────────

    public function testFlushClassFastSkipsDeleteOldVersions(): void
    {
        Config::modify()->set(SweeperClearArchiveTask::class, 'keep', 2);
        $record = $this->makeVersions(5);
        $id = (int) $record->ID;

        $task = SweeperClearArchiveTask::create();
        $task->setFast(true);
        $task->setDry(false);

        // fast → deleteOldVersions skipped; the archived-retention pass prunes instead,
        // so the non-archived "old versions" message must be absent.
        $output = $this->discardOutput(static fn () => $task->flushClass(VersionedRecord::class));

        self::assertStringContainsString('old archived versions', $output);
        self::assertStringNotContainsString('Cleared 3 old versions', $output);
        self::assertSame(2, $this->versionCount(VersionedRecord::class, $id));
    }

    public function testFlushClassNonFastPrunesViaDeleteOldVersions(): void
    {
        Config::modify()->set(SweeperClearArchiveTask::class, 'keep', 2);
        $record = $this->makeVersions(5);
        $id = (int) $record->ID;

        $task = SweeperClearArchiveTask::create();
        $task->setFast(false);
        $task->setDry(false);

        $output = $this->discardOutput(static fn () => $task->flushClass(VersionedRecord::class));

        self::assertStringContainsString('Cleared 3 old versions', $output);
        self::assertSame(2, $this->versionCount(VersionedRecord::class, $id));
    }

    // ── getBaseVersionedClasses() filtering ──────────────────────

    public function testBaseVersionedClassesIncludesVersionedDirectSubclass(): void
    {
        $task = ExposedClearArchiveTask::create();
        $classes = iterator_to_array($task->exposedGetBaseVersionedClasses());

        self::assertContains(VersionedRecord::class, $classes);
    }

    public function testBaseVersionedClassesExcludesNonVersioned(): void
    {
        $task = ExposedClearArchiveTask::create();
        $classes = iterator_to_array($task->exposedGetBaseVersionedClasses());

        self::assertNotContains(NonVersionedRecord::class, $classes);
    }

    public function testBaseVersionedClassesExcludesNonDirectSubclass(): void
    {
        $task = ExposedClearArchiveTask::create();
        $classes = iterator_to_array($task->exposedGetBaseVersionedClasses());

        self::assertNotContains(VersionedRecordChild::class, $classes);
    }
}
