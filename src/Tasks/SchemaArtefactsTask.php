<?php

namespace Sweeper\Tasks;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use Sweeper\Output\TaskOutput;
use Sweeper\Schema\DropPlan;
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
 * This task replaces the former `sweeper-artefacts` task, which built a full
 * temporary database to diff against.
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

        Unlike a temporary-database approach this requires NO CREATE DATABASE privilege and no
        temporary database. Special index types (fulltext/hash/rtree) keep their
        type because the schema is recorded before the engine-specific render.

        NOTE: anything in the database that is not part of the SilverStripe schema
        WILL be reported, and removed when run with run=yes. Destructive runs
        require the confirmation token printed by a previous dry-run
        (run=yes token=...).
        DESCRIPTION;

    private bool $dryRun = true;

    private TaskOutput $out;

    public function run($request): int
    {
        $this->dryRun = !($request->getVar('run') === 'yes');
        $this->out = TaskOutput::create('Sweeper: schema artefacts', $this->dryRun);

        $connection = DB::get_conn();
        if (!$connection || !$connection->isActive()) {
            $this->out->warning('No active database connection found');
            $this->out->finish();
            return 1;
        }

        $this->out->line('Reading current database schema');
        $current = $this->captureCurrentSchema();

        $this->out->line('Recording reference schema (no temp database)');
        try {
            $clean = $this->captureReferenceSchema();
        } catch (\Throwable $e) {
            // A partial reference schema is unsafe to diff (it would flag real
            // artefacts as orphaned), so abort without changing anything.
            $this->out->warning('Failed to record reference schema: ' . $e->getMessage());
            $this->out->info('Aborting without changes.');
            $this->out->finish();
            return 1;
        }

        $this->out->line(count($clean) . ' reference tables recorded, ' . count($current) . ' tables in database');
        $this->out->line('Comparing schemas');

        $droppable = SchemaDiff::diff($current, $clean);
        $hasDroppables = $droppable['tables'] || $droppable['columns'] || $droppable['indexes'];
        $token = SchemaDiff::confirmationToken($droppable);

        if (!$this->dryRun && $hasDroppables) {
            $given = (string)$request->getVar('token');
            if (!hash_equals($token, $given)) {
                $this->out->warning('REFUSED: missing or stale confirmation token.');
                $this->out->info(
                    'Run this task without run=yes first and review its output; it prints the token to use. '
                    . 'A previously valid token means the droppable set changed since your review '
                    . '(deploy, dev/build or manual schema change). Review again.'
                );
                $this->out->finish();
                return 1;
            }
        }

        // Preview every droppable artefact (always, even on dry-run).
        $this->reportTables($droppable['tables']);
        $this->reportIndexes($droppable['indexes']);
        $this->reportColumns($droppable['columns']);

        // Execute in DropPlan's dependency-safe order (tables, then indexes,
        // then columns). Centralising the order there keeps it in one tested
        // place instead of relying on the call order here.
        if (!$this->dryRun) {
            foreach (DropPlan::statements($droppable) as $statement) {
                DB::query($statement);
            }
        }

        $this->out->summary([
            'Tables' => count($droppable['tables']),
            'Columns' => array_sum(array_map('count', $droppable['columns'])),
            'Indexes' => array_sum(array_map('count', $droppable['indexes'])),
            'Mode' => $this->dryRun ? 'dry-run (nothing changed)' : 'executed',
        ]);

        if ($this->dryRun && $hasDroppables) {
            $this->out->action('CLI', "vendor/bin/sake dev/tasks/sweeper-schema-artefacts run=yes token={$token}");
            $this->out->action('URL', "/dev/tasks/sweeper-schema-artefacts?run=yes&token={$token}");
        }

        $this->out->finish();
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

    private function reportTables(array $tables): void
    {
        if (!$tables) {
            $this->out->line('No droppable tables');
            return;
        }

        $this->out->section('Droppable tables', count($tables));
        $this->out->items($tables);
    }

    private function reportColumns(array $columnsByTable): void
    {
        if (!$columnsByTable) {
            $this->out->line('No droppable columns');
            return;
        }

        $rows = [];
        foreach ($columnsByTable as $table => $columns) {
            $rows[] = [$table, implode(', ', $columns)];
        }

        $this->out->section('Droppable columns', array_sum(array_map('count', $columnsByTable)));
        $this->out->table(['Table', 'Columns'], $rows);
    }

    private function reportIndexes(array $indexesByTable): void
    {
        if (!$indexesByTable) {
            $this->out->line('No droppable indexes');
            return;
        }

        $rows = [];
        foreach ($indexesByTable as $table => $indexes) {
            $rows[] = [$table, implode(', ', $indexes)];
        }

        $this->out->section('Droppable indexes', array_sum(array_map('count', $indexesByTable)));
        $this->out->table(['Table', 'Indexes'], $rows);
    }
}
