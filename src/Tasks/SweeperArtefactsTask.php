<?php

namespace App\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\Connect\TempDatabase;
use SilverStripe\ORM\DB;

class SweeperArtefacts extends BuildTask
{
    private static string $segment = 'sweeper-artefacts';

    private bool $dryRun = true;

    protected $description = <<<DESCRIPTION
        Builds a clean in-memory database and compares it with the schema of the currently configured database,
        will then run a diff of both schemas to discern any extraneous tables or columns that can be removed.

        NOTE: This means that anything that is stored in the database that is not defined in the silverstripe schema
        WILL be removed.
        DESCRIPTION;

    public function run($request): int
    {
        $this->dryRun = !($request->getVar('run') === 'yes');

        $mapIndexListToName = static function ($indexEntry) {
            return $indexEntry['name'];
        };

        $this->log('Checking current DB state');

        // Current schema
        $currentConnection = DB::get_conn();
        $currentSchemaManager = DB::get_schema();

        if (!$currentConnection || !$currentConnection->isActive()) {
            $this->log('No current connection found');
            return 1;
        }

        $currentDatabase = $currentConnection->getSelectedDatabase();

        if (!$currentSchemaManager) {
            $this->log('No current schema manager found');
            return 1;
        }

        $currentSchema = [];

        foreach ($currentSchemaManager->tableList() as $lower => $tableName) {
            $currentSchema[$tableName] = [
                'indexes' => array_map($mapIndexListToName, $currentSchemaManager->indexList($tableName)),
                'columns' => array_keys($currentSchemaManager->fieldList($tableName)),
            ];
        }

        // Clean new schema
        $this->log('Building clean schema to compare');

        $cleanDB = TempDatabase::create();
        $cleanDB->build();
        $cleanSchemaManager = DB::get_schema();

        if (!$cleanSchemaManager) {
            $this->log('No clean schema manager found');
            return 1;
        }

        $cleanSchema = [];

        foreach (DB::table_list() as $lower => $tableName) {
            $cleanSchema[$tableName] = [
                'indexes'=> array_map($mapIndexListToName, $cleanSchemaManager->indexList($tableName)),
                'columns' => array_keys($cleanSchemaManager->fieldList($tableName)),
            ];
        }
        $cleanDB->kill();

        // Re-connect to make the connection active again since the kill command fucks with our current
        // connection for some reason.
        $currentConnection = DB::connect(DB::getConfig());

        if (!$currentConnection || !$currentConnection->isActive()) {
            $this->log('No current connection found');
            return 1;
        }

        $currentConnection->selectDatabase($currentDatabase);

        $droppableSchema = [
            'tables' => [],
            'columns' => [],
            'indexes' => [],
        ];

        $this->log('Comparing state');
        foreach ($currentSchema as $tableName => $tableInfo) {
            if (!array_key_exists($tableName, $cleanSchema)) {
                $droppableSchema['tables'][] = $tableName;
                continue;
            }

            $columnDiff = array_diff($tableInfo['columns'], $cleanSchema[$tableName]['columns']);
            $indexDiff = array_diff($tableInfo['indexes'], $cleanSchema[$tableName]['indexes']);

            if (count($columnDiff)) {
                $droppableSchema['columns'][$tableName] = $columnDiff;
            }

            if (count($indexDiff)) {
                $droppableSchema['indexes'][$tableName] = $indexDiff;
            }
        }

        $this->log('Parsing states to diff');
        $this->dropTables($droppableSchema['tables']);
        $this->dropIndexes($droppableSchema['indexes']);
        $this->dropColumns($droppableSchema['columns']);

        if ($this->dryRun) {
            $this->log('Running in dry-run mode, to do the actual actions please run with run=yes', true, true);
        }

        return 0;
    }

    private function dropTables(array $tableList): void
    {
        if (!count($tableList)) {
            $this->log('No droppable tables', true, true);
            return;
        }

        $this->log("Found " . count($tableList) . " tables to drop", true, true);
        foreach ($tableList as $tableName) {
            $this->log("Dropping $tableName");

            if ($this->dryRun) {
                continue;
            }

            DB::query("DROP TABLE IF EXISTS `$tableName`");
        }
        $this->log("Dropped " . count($tableList) . " tables", true, true);
    }

    private function dropIndexes(array $indexList): void
    {
        if (!count($indexList)) {
            $this->log('No droppable indexes', true, true);
            return;
        }

        $indexCount = 0;

        $this->log('Found ' . count($indexList) . ' tables with droppable indexes', true, true);
        foreach ($indexList as $tableName => $indexes) {
            $indexCount += count($indexes);
            $this->log("$tableName indexes: " . implode(', ', $indexes));

            if ($this->dryRun) {
                continue;
            }

            foreach ($indexes as $index) {
                DB::query("DROP INDEX `$index` ON `$tableName`");
            }
        }

        $this->log("Dropped $indexCount indexes", true, true);
    }

    private function dropColumns(array $columnList): void
    {
        if (!count($columnList)) {
            $this->log('No droppable columns', true, true);
            return;
        }

        $columnCount = 0;

        $this->log('Found ' . count($columnList) . ' tables with droppable columns', true, true);
        foreach ($columnList as $tableName => $columns) {
            $columnCount += count($columns);
            $this->log("$tableName columns: " . implode(', ', $columns));

            if ($this->dryRun) {
                continue;
            }

            foreach ($columns as $column) {
                DB::query("ALTER TABLE `$tableName` DROP COLUMN `$column`");
            }
        }

        $this->log("Dropped $columnCount columns", true, true);
    }

    private function log(string $message, bool $emptyLineBefore = false, bool $emptyLineAfter = false): void
    {
        echo ($emptyLineBefore ? "\n" : '') . (new \DateTime())->format(DATE_ATOM) . ': ' .  ($this->dryRun ? '(dry-run) ' : '') . $message . "\n" . ($emptyLineAfter ? "\n" : '');
    }
}
