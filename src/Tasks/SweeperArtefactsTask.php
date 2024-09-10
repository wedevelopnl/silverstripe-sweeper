<?php

namespace Sweeper\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\Connect\TempDatabase;
use SilverStripe\ORM\DB;

class SweeperArtefacts extends BuildTask
{
    private static string $segment = 'sweeper-artefacts';

    private static bool $dryRun = true;

    protected $description = <<<DESCRIPTION
        Builds a clean in-memory database and compares it with the schema of the currently configured database,
        will then run a diff of both schemas to discern any extraneous tables or columns that can be removed.

        NOTE: This means that anything that is stored in the database that is not defined in the silverstripe schema
        WILL be removed.
        DESCRIPTION;

    public function run($request): int
    {
        self::$dryRun = !($request->getVar('run') === 'yes');

        $mapIndexListToName = static function ($indexEntry) {
            return $indexEntry['name'];
        };

        self::log('Checking current DB state');

        // Current schema
        $currentConnection = DB::get_conn();
        $currentSchemaManager = DB::get_schema();

        if (!$currentConnection || !$currentConnection->isActive()) {
            self::log('No current connection found');
            return 1;
        }

        $currentDatabase = $currentConnection->getSelectedDatabase();

        if (!$currentSchemaManager) {
            self::log('No current schema manager found');
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
        self::log('Building clean schema to compare');

        $cleanDB = new TempDatabase();
        $cleanDB->build();
        $cleanSchemaManager = DB::get_schema();

        if (!$cleanSchemaManager) {
            self::log('No clean schema manager found');
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
            self::log('No current connection found');
            return 1;
        }

        $currentConnection->selectDatabase($currentDatabase);

        $droppableSchema = [
            'tables' => [],
            'columns' => [],
            'indexes' => [],
        ];

        self::log('Comparing state');
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

        self::log('Parsing states to diff');
        self::dropTables($droppableSchema['tables']);
        self::dropIndexes($droppableSchema['indexes']);
        self::dropColumns($droppableSchema['columns']);

        if (self::$dryRun) {
            self::log('Running in dry-run mode, to do the actual actions please run with run=yes', true, true);
        }

        return 0;
    }

    private static function dropTables(array $tableList): void
    {
        if (!count($tableList)) {
            self::log('No droppable tables', true, true);
            return;
        }

        self::log("Found " . count($tableList) . " tables to drop", true, true);
        foreach ($tableList as $tableName) {
            self::log("Dropping $tableName");
        }
        self::log("Dropped " . count($tableList) . " tables", true, true);
    }

    private static function dropIndexes(array $indexList): void
    {
        if (!count($indexList)) {
            self::log('No droppable indexes', true, true);
            return;
        }

        $indexCount = 0;

        self::log('Found ' . count($indexList) . ' tables with droppable indexes', true, true);
        foreach ($indexList as $tableName => $indexes) {
            $indexCount += count($indexes);
            self::log("$tableName indexes: " . implode(', ', $indexes));
        }

        self::log("Dropped $indexCount indexes", true, true);
    }

    private static function dropColumns(array $columnList): void
    {
        if (!count($columnList)) {
            self::log('No droppable columns', true, true);
            return;
        }

        $columnCount = 0;

        self::log('Found ' . count($columnList) . ' tables with droppable columns', true, true);
        foreach ($columnList as $tableName => $columns) {
            $columnCount += count($columns);
            self::log("$tableName columns: " . implode(', ', $columns));
        }

        self::log("Dropped $columnCount columns", true, true);
    }

    private static function log(string $message, bool $emptyLineBefore = false, bool $emptyLineAfter = false): void
    {
        echo ($emptyLineBefore ? "\n" : '') . (new \DateTime())->format(DATE_ATOM) . ': ' .  (self::$dryRun ? '(dry-run) ' : '') . $message . "\n" . ($emptyLineAfter ? "\n" : '');
    }
}
