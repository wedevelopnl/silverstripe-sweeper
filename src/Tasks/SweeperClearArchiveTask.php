<?php

namespace Sweeper\Tasks;

use Composer\InstalledVersions;
use Generator;
use InvalidArgumentException;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\HTML;

class SweeperClearArchiveTask extends BuildTask
{
    private const SNAPSHOTS_EVENT_CLASS = 'SilverStripe\Snapshots\SnapshotEvent';
    private const SNAPSHOT_CLASS = 'SilverStripe\Snapshots\Snapshot';
    private const SNAPSHOT_ACTIVITY_ENTRY_CLASS = 'SilverStripe\Snapshots\ActivityEntry';
    private static string $segment = 'sweeper-archive';

    protected $title = "Prunes pages and objects version archive";

    protected $description = <<<DESCRIPTION
        Prunes backlog of version history to a fixed number per record, as well as
        any versions for archived or orphaned records. Note that this module will make
        deleted objects unrecoverable.
        Run with ?run=yes to acknowledge that deleted pages cannot be recovered,
        and that you have made a backup manually, or run with ?run=dry to dry-run.

        (Optional) Set keep=<num> (default: 10) to specify number of versions to keep.

        NOTE: If running with the snapshots cleanup enabled, it is most likely necessary to temporarily increase the `max_prepared_stmt_count` on a database level.
        DESCRIPTION;

    protected int $keepVersions = 10;
    protected bool $dry = false;
    protected bool $fast = false;

    public function run($request): void
    {
        $run = $request->getVar('run');
        if (!in_array($run, ['dry', 'yes', 'fast'], true)) {
            throw new InvalidArgumentException("Please provide the 'run' argument with either 'yes', 'dry', or 'fast'");
        }
        $this->setDry($run === 'dry');
        $this->setFast($run === 'fast');

        // With slow requests, need to increase time limit to 1 hour
        if (!$this->isFast() && !$this->isDry()) {
            Environment::increaseTimeLimitTo(3600);
            Environment::increaseMemoryLimitTo();
        }

        // Set keep versions
        $this->setKeepVersions((int)$request->getVar('keep') ?: (int)self::config()->get('keep'));

        // Loop over all versioned classes
        foreach ($this->getBaseVersionedClasses() as $class) {
            if (self::hasSnapshots() && !$this->isFast()) {
                $this->flushSnapshots($class);
            }

            $this->flushClass($class);
        }

        $this->message("Flush complete!");
    }

    protected function getBaseVersionedClasses(): Generator
    {
        foreach ($this->directSubclasses(DataObject::class) as $class) {
            $shouldYieldClass = DataObject::has_extension($class, Versioned::class);

            if (self::hasSnapshots()) {
                $shouldYieldClass = $shouldYieldClass && $class !== self::SNAPSHOTS_EVENT_CLASS;
            }

            if ($shouldYieldClass) {
                yield $class;
            }
        }
    }

    protected function directSubclasses(string $class): Generator
    {
        foreach (ClassInfo::subclassesFor($class) as $subclass) {
            if (get_parent_class($subclass) === $class) {
                yield $subclass;
            }
        }
    }

    public function flushClass(string $class): void
    {
        $this->message("Beginning flush for {$class}\n");

        // Delete old versions for non-deleted records (note: Can be slow on large recordsets)
        if (!$this->isFast()) {
            $this->deleteOldVersions($class);
        }

        // Clear all obsolete versions for deleted records
        $this->deleteArchivedVersionsWithVersionRetention($class);

        // Flush all subclass tables
        $this->deleteOrphanedVersions($class);

        // Yay
        $this->message("Done flushing {$class}\n");
    }

    protected function message(string $string): void
    {
        if (Director::is_cli()) {
            echo "{$string}\n";
        } else {
            echo HTML::createTag('p', [], Convert::raw2xml($string));
        }
    }

    public function flushSnapshots(string $class): void
    {
        $prefix = $this->isDry() ? '(dry-run): ' : '';
        $this->message($prefix . "Beginning snapshot flush for $class\n");
        $objects = $class::get();

        $totalClearedSnapshotCount = 0;
        $totalKeptSnapshotCount = 0;
        foreach ($objects as $object) {
            try {
                $clearedSnapshotCounts = 0;
                $keptSnapshotCounts = 0;

                $list = $object->getRelevantSnapshots();
                $list = $list->sort('"LastEdited"', 'DESC');
                $objectHash = (self::SNAPSHOT_CLASS)::hashObjectForSnapshot($object);

                $fullVersions = 0;
                foreach ($list as $snapshot) {
                    // A full version is a change not to a subset of an object, like a related element block,
                    // but a change to the root object.
                    $isFullVersion = $snapshot->OriginHash === $objectHash &&
                        $snapshot->getActivityType() !== (self::SNAPSHOT_ACTIVITY_ENTRY_CLASS)::DELETED;

                    if ($isFullVersion) {
                        $fullVersions++;
                    }

                    if ($fullVersions <= $this->getKeepVersions()) {
                        $keptSnapshotCounts++;

                        continue;
                    }

                    // Delete snapshots from this point
                    $clearedSnapshotCounts++;

                    if (!$this->isDry()) {
                        // @TODO Improvement: Replace with a direct SQL query for performance reasons
                        $snapshot->delete();
                    }
                }

                $totalClearedSnapshotCount += $clearedSnapshotCounts;
                $totalKeptSnapshotCount += $keptSnapshotCounts;
                $this->message($prefix . "Cleared $clearedSnapshotCounts snapshots for $class: $object->ID");
                $this->message($prefix . "Kept $keptSnapshotCounts snapshots for $class: $object->ID");
            } catch (\Exception $e) {
                $this->message($prefix . "Exception during parsing of object $class: $object->ID ({$e->getMessage()})");
            }
        }

        $this->message($prefix . "Cleared $totalClearedSnapshotCount snapshots for $class");
        $this->message($prefix . "Kept $totalKeptSnapshotCount snapshots for $class");
    }

    public function deleteArchivedVersionsWithVersionRetention(string $class): void
    {
        $baseTable = DataObject::getSchema()->tableName($class);
        $baseVersionedTable = "{$baseTable}_Versions";
        $clearedVersionCounts = 0;

        $distinctRecords = DB::query(<<<SQL
            SELECT DISTINCT "RecordID" FROM "{$baseVersionedTable}"
            SQL);

        foreach ($distinctRecords as $queryResult) {
            $versionedRecordID = $queryResult['RecordID'];

            $versionBound = DB::prepared_query(
                <<<SQL
                    SELECT "Version" FROM "{$baseVersionedTable}"
                    WHERE "RecordID" = ?
                    ORDER BY "Version" DESC
                    LIMIT {$this->getKeepVersions()}, 1
                    SQL
                ,
                [$versionedRecordID]
            )->value();

            // Record has fewer than keepVersions versions
            if (!$versionBound) {
                continue;
            }

            $query = SQLSelect::create()
                ->setFrom("\"{$baseVersionedTable}\"")
                ->addWhere([
                    "\"{$baseVersionedTable}\".\"RecordID\" = ?" => $versionedRecordID,
                    "\"{$baseVersionedTable}\".\"Version\" <= ?" => $versionBound,
                ]);

            // Delete or count
            if ($this->isDry()) {
                $count = $query->setSelect('COUNT(*)')->execute()->value();
            } else {
                $delete = $query->toDelete();
                $delete->execute();
                $count = DB::affected_rows();
            }

            $clearedVersionCounts += $count;
        }

        // Log output
        if ($clearedVersionCounts) {
            $prefix = $this->isDry() ? '(dry-run): ' : '';
            $this->message(
                <<<MESSAGE
                    {$prefix}Cleared {$clearedVersionCounts} old archived versions (before last {$this->getKeepVersions()}) from table {$baseVersionedTable}
                    MESSAGE
            );
        }
    }

    /**
     * Delete all old versions of a record
     *
     * @param string $class
     */
    public function deleteOldVersions(string $class): void
    {
        // E.g. `SiteTree`
        $baseTable = DataObject::getSchema()->tableName($class);
        $baseVersionedTable = "{$baseTable}_Versions";

        // Clear all except keepVersions num of max versions
        $clearedVersionCounts = 0;
        foreach ($this->getDraftObjects($class) as $object) {
            // Get version to keep
            $versionBound = DB::prepared_query(
                <<<SQL
                    SELECT "Version" FROM "{$baseVersionedTable}"
                    WHERE "RecordID" = ?
                    ORDER BY "Version" DESC
                    LIMIT {$this->getKeepVersions()}, 1
                    SQL
                ,
                [$object->ID]
            )->value();

            // Record has fewer than keepVersions versions
            if (!$versionBound) {
                continue;
            }

            $query = SQLSelect::create()
                ->setFrom("\"{$baseVersionedTable}\"")
                ->addWhere([
                    "\"{$baseVersionedTable}\".\"RecordID\" = ?" => $object->ID,
                    "\"{$baseVersionedTable}\".\"Version\" <= ?" => $versionBound,
                ]);

            // Delete or count
            if ($this->isDry()) {
                $count = $query->setSelect('COUNT(*)')->execute()->value();
            } else {
                $delete = $query->toDelete();
                $delete->execute();
                $count = DB::affected_rows();
            }

            $clearedVersionCounts += $count;
        }

        // Log output
        if ($clearedVersionCounts) {
            $prefix = $this->isDry() ? '(dry-run): ' : '';
            $this->message(
                <<<MESSAGE
                    {$prefix}Cleared {$clearedVersionCounts} old versions (before last {$this->getKeepVersions()}) from table {$baseVersionedTable}
                    MESSAGE
            );
        }
    }

    /**
     * Delete any version that isn't in draft anymore
     *
     * @param string $class
     */
    public function deleteArchivedVersions(string $class): void
    {
        // E.g. `SiteTree`
        $baseTable = DataObject::getSchema()->tableName($class);
        $baseVersionedTable = "{$baseTable}_Versions";

        $query = SQLSelect::create()
            ->setFrom("\"{$baseVersionedTable}\"")
            ->addLeftJoin(
                $baseTable,
                "\"{$baseVersionedTable}\".\"RecordID\" = \"{$baseTable}\".\"ID\""
            )
            ->addWhere("\"{$baseTable}\".\"ID\" IS NULL");

        // If dry-run, output result
        if ($this->isDry()) {
            $count = $query->setSelect('COUNT(*)')->execute()->value();
        } else {
            // Only delete versioned table, not base
            $delete = $query->toDelete();
            $delete->setDelete("\"{$baseVersionedTable}\"");
            $delete->execute();
            $count = DB::affected_rows();
        }

        // Log output
        if ($count) {
            $prefix = $this->isDry() ? '(dry-run): ' : '';
            $this->message("{$prefix}Cleared {$count} rows from {$baseVersionedTable} for deleted records");
        }
    }

    /**
     * @param string $class
     */
    public function deleteOrphanedVersions(string $class): void
    {
        // E.g. `SiteTree`
        $baseTable = DataObject::getSchema()->tableName($class);
        $baseVersionedTable = "{$baseTable}_Versions";

        foreach (ClassInfo::dataClassesFor($class) as $subclass) {
            // Skip base record
            $subTable = DataObject::getSchema()->tableName($subclass);
            if ($subTable === $baseTable) {
                continue;
            }
            $versionedTable = "{$subTable}_Versions";

            $query = SQLSelect::create()
                ->setFrom("\"{$versionedTable}\"")
                ->addLeftJoin(
                    $baseVersionedTable,
                    <<<JOIN
                        "{$versionedTable}"."RecordID" = "{$baseVersionedTable}"."RecordID" AND
                        "{$versionedTable}"."Version" = "{$baseVersionedTable}"."Version"
                        JOIN
                )
                ->addWhere("\"{$baseVersionedTable}\".\"ID\" IS NULL");

            // If dry-run, output result
            if ($this->isDry()) {
                $count = $query->setSelect('COUNT(*)')->execute()->value();
            } else {
                // Only delete versioned table, not base
                $delete = $query->toDelete();
                $delete->setDelete("\"{$versionedTable}\"");
                $delete->execute();
                $count = DB::affected_rows();
            }

            // Log output
            if ($count) {
                $prefix = $this->isDry() ? '(dry-run): ' : '';
                $this->message("{$prefix}Cleared {$count} rows from {$versionedTable}");
            }
        }
    }

    /**
     * @return int
     */
    public function getKeepVersions(): int
    {
        return self::config()->get('keep') ?: $this->keepVersions;
    }

    /**
     * @param int $keepVersions
     * @return $this
     */
    public function setKeepVersions(int $keepVersions): self
    {
        $this->keepVersions = $keepVersions;
        return $this;
    }

    /**
     * @return bool
     */
    public function isDry(): bool
    {
        return $this->dry;
    }

    /**
     * @param bool $dry
     * @return $this
     */
    public function setDry(bool $dry): self
    {
        $this->dry = $dry;
        return $this;
    }

    /**
     * @return bool
     */
    public function isFast(): bool
    {
        return $this->fast;
    }

    /**
     * @param bool $fast
     * @return $this
     */
    public function setFast(bool $fast): self
    {
        $this->fast = $fast;
        return $this;
    }

    /**
     * Get draft records in list
     *
     * @param class-string<DataObject> $class
     * @return Generator|DataObject[]
     */
    protected function getDraftObjects(string $class)
    {
        // Batch all queries into groups of 100 records
        $allRecords = Versioned::get_by_stage($class, Versioned::DRAFT);
        $count = $allRecords->count();
        $batches = (int)($count / 100) + 1;
        for ($batch = 0; $batch < $batches; $batch++) {
            $batchRecords = $allRecords->where([
                'MOD("' . $class::config()->get('table_name') . '"."ID", ?) = ?' => [
                    $batches,
                    $batch,
                ],
            ]);
            foreach ($batchRecords as $record) {
                yield $record;
            }

            // Flush records
            gc_collect_cycles();
        }
    }

    private static function hasSnapshots(): bool
    {
        return InstalledVersions::isInstalled('silverstripe/versioned-snapshots');
    }
}
