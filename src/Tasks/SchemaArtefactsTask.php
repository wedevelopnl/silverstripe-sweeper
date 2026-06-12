<?php

namespace Sweeper\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use Sweeper\Schema\RecordingSchemaManager;
use Sweeper\Schema\SchemaDiff;

/**
 * Finds (and optionally removes) orphaned tables, columns and indexes WITHOUT
 * creating a temporary database.
 *
 * The reference schema is built by running SilverStripe's own
 * requireTable()/augmentDatabase() path against a RecordingSchemaManager (see
 * that class). This captures everything dev/build would create, including
 * Versioned/_Live/many_many tables and special index types, but needs no
 * CREATE DATABASE privilege.
 *
 * This is a separate task from `sweeper-artefacts`; that task is left untouched.
 *
 * Modes (via ?run=):
 *  - (default)  dry-run: report droppable artefacts and print a confirmation token.
 *  - yes        execute the DROP statements; requires token=<value> from a
 *               previous dry-run. The token is a hash of the droppable set, so it
 *               goes stale (and execution is refused) when the schema changed
 *               since the review.
 *
 * Indexes are matched by signature (type + columns); the PRIMARY key is never
 * dropped.
 */
class SchemaArtefactsTask extends BuildTask
{
    private static string $segment = 'sweeper-schema-artefacts';

    protected $title = 'Sweeper: schema artefacts (no temp database)';

    protected $description = <<<DESCRIPTION
        Finds orphaned tables, columns and indexes by recording the schema that
        SilverStripe would build (the same requireTable()/augmentDatabase() path as
        dev/build) and diffing it against the live database.

        Unlike sweeper-artefacts this requires NO CREATE DATABASE privilege and no
        temporary database. Special index types (fulltext/hash/rtree) keep their
        type because the schema is recorded before the engine-specific render.

        NOTE: anything in the database that is not part of the SilverStripe schema
        WILL be reported, and removed when run with run=yes. Destructive runs
        require the confirmation token printed by a previous dry-run
        (run=yes token=...).
        DESCRIPTION;

    private bool $dryRun = true;

    public function run($request): int
    {
        $this->dryRun = !($request->getVar('run') === 'yes');

        $connection = DB::get_conn();
        if (!$connection || !$connection->isActive()) {
            $this->log('No active database connection found');
            return 1;
        }

        $this->log('Reading current database schema');
        $current = $this->captureCurrentSchema();

        $this->log('Recording reference schema (no temp database)');
        try {
            $clean = $this->captureReferenceSchema();
        } catch (\Throwable $e) {
            // A partial reference schema is unsafe to diff (it would flag real
            // artefacts as orphaned), so abort without changing anything.
            $this->log('Failed to record reference schema: ' . $e->getMessage());
            $this->log('Aborting without changes.', true, true);
            return 1;
        }

        $this->log(count($clean) . ' reference tables recorded, ' . count($current) . ' tables in database');

        $this->log('Comparing schemas');
        $droppable = SchemaDiff::diff($current, $clean);

        $hasDroppables = $droppable['tables'] || $droppable['columns'] || $droppable['indexes'];
        $token = SchemaDiff::confirmationToken($droppable);

        if (!$this->dryRun && $hasDroppables) {
            $given = (string)$request->getVar('token');
            if (!hash_equals($token, $given)) {
                $this->log('REFUSED: missing or stale confirmation token.', true);
                $this->log('Run this task without run=yes first and review its output; it prints the token to use.');
                $this->log(
                    'A previously valid token means the droppable set changed since your review '
                    . '(deploy, dev/build or manual schema change). Review again.',
                    false,
                    true
                );
                return 1;
            }
        }

        $this->dropTables($droppable['tables']);
        $this->dropColumns($droppable['columns']);
        $this->dropIndexes($droppable['indexes']);

        if ($this->dryRun && $hasDroppables) {
            $this->log("To execute exactly the set above, re-run with: run=yes token=$token", true, true);
        } elseif ($this->dryRun) {
            $this->log('Nothing to drop.', true, true);
        }

        return 0;
    }

    /**
     * Read the live schema via the active (real) schema manager.
     *
     * @return array<string, array{columns: string[], indexes: array}>
     */
    private function captureCurrentSchema(): array
    {
        $schema = DB::get_schema();
        $current = [];

        foreach ($schema->tableList() as $tableName) {
            $indexes = [];
            foreach ($schema->indexList($tableName) as $name => $info) {
                $indexes[] = [
                    'name' => is_array($info) ? ($info['name'] ?? $name) : $name,
                    'columns' => is_array($info) ? ($info['columns'] ?? []) : [],
                    'type' => is_array($info) ? ($info['type'] ?? 'index') : 'index',
                ];
            }

            $current[$tableName] = [
                'columns' => array_keys($schema->fieldList($tableName)),
                'indexes' => $indexes,
            ];
        }

        return $current;
    }

    /**
     * Build the reference schema by recording requireTable()/augmentDatabase()
     * output, without touching a database.
     *
     * @return array<string, array{columns: string[], indexes: array}>
     */
    private function captureReferenceSchema(): array
    {
        $connection = DB::get_conn();
        $realSchema = $connection->getSchemaManager();
        $recorder = new RecordingSchemaManager();
        $recorder->quiet();

        try {
            // Make the recorder the active schema manager so requireTable() and
            // every augmentDatabase() route their require_* calls into it.
            $connection->setSchemaManager($recorder);

            $dataClasses = ClassInfo::subclassesFor(DataObject::class);
            array_shift($dataClasses); // remove DataObject itself

            // Mirror TableBuilder::buildTables() without depending on it directly.
            $recorder->schemaUpdate(function () use ($dataClasses) {
                foreach ($dataClasses as $class) {
                    if (!class_exists($class)) {
                        continue;
                    }
                    $singleton = DataObject::singleton($class);
                    if ($singleton instanceof TestOnly) {
                        continue;
                    }
                    $singleton->requireTable();
                }
            });
        } finally {
            // ALWAYS restore the real schema manager, even on error.
            $connection->setSchemaManager($realSchema);
        }

        // Normalise the recorded specs into the uniform diff format.
        $clean = [];
        foreach ($recorder->getRecorded() as $table => $data) {
            $indexes = [];
            foreach ($data['indexes'] as $name => $spec) {
                $indexes[] = [
                    'name' => is_array($spec) ? ($spec['name'] ?? $name) : $name,
                    'columns' => is_array($spec) ? ($spec['columns'] ?? []) : [],
                    'type' => is_array($spec) ? ($spec['type'] ?? 'index') : 'index',
                ];
            }
            $clean[$table] = [
                'columns' => $data['columns'],
                'indexes' => $indexes,
            ];
        }

        return $clean;
    }

    private function dropTables(array $tables): void
    {
        if (!$tables) {
            $this->log('No droppable tables', true, true);
            return;
        }

        $this->log('Found ' . count($tables) . ' droppable tables', true, true);
        foreach ($tables as $table) {
            $this->log("Dropping table $table");
            if ($this->dryRun) {
                continue;
            }
            DB::query("DROP TABLE IF EXISTS \"$table\"");
        }
    }

    private function dropColumns(array $columnsByTable): void
    {
        if (!$columnsByTable) {
            $this->log('No droppable columns', true, true);
            return;
        }

        $count = 0;
        $this->log('Found ' . count($columnsByTable) . ' tables with droppable columns', true, true);
        foreach ($columnsByTable as $table => $columns) {
            $count += count($columns);
            $this->log("$table columns: " . implode(', ', $columns));
            if ($this->dryRun) {
                continue;
            }
            foreach ($columns as $column) {
                DB::query("ALTER TABLE \"$table\" DROP COLUMN \"$column\"");
            }
        }
        $this->log("$count columns" . ($this->dryRun ? ' would be dropped' : ' dropped'));
    }

    private function dropIndexes(array $indexesByTable): void
    {
        if (!$indexesByTable) {
            $this->log('No droppable indexes', true, true);
            return;
        }

        $count = 0;
        $this->log('Found ' . count($indexesByTable) . ' tables with droppable indexes', true, true);
        foreach ($indexesByTable as $table => $indexes) {
            foreach ($indexes as $index) {
                // Defence in depth: the diff already excludes PRIMARY.
                if (strtoupper((string)$index) === 'PRIMARY') {
                    continue;
                }
                $count++;
                $this->log("$table index: $index");
                if ($this->dryRun) {
                    continue;
                }
                DB::query("DROP INDEX \"$index\" ON \"$table\"");
            }
        }
        $this->log("$count indexes" . ($this->dryRun ? ' would be dropped' : ' dropped'));
    }

    private function log(string $message, bool $emptyLineBefore = false, bool $emptyLineAfter = false): void
    {
        // Plain newlines collapse in the browser; TaskRunner serves HTML there.
        $eol = Director::is_cli() ? "\n" : "<br>\n";

        echo ($emptyLineBefore ? $eol : '')
            . (new \DateTime())->format(DATE_ATOM) . ': '
            . ($this->dryRun ? '(dry-run) ' : '')
            . $message . $eol
            . ($emptyLineAfter ? $eol : '');
    }
}
