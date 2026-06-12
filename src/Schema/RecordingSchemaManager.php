<?php

namespace Sweeper\Schema;

use SilverStripe\ORM\Connect\MySQLSchemaManager;

/**
 * A schema manager that RECORDS the schema SilverStripe would build, instead of
 * writing it to a database.
 *
 * Background: `dev/build` and `TempDatabase` both produce the canonical schema
 * through `TableBuilder::buildTables()`, which runs `DataObject::requireTable()`
 * plus every extension's `augmentDatabase()`. Those calls buffer the desired
 * tables, columns and indexes and then, at the end of
 * `DBSchemaManager::schemaUpdate()`, flush them via `createTable()`/`alterTable()`.
 *
 * By installing this manager as the active schema manager during a build (see
 * SchemaArtefactsTask) and intercepting `createTable()`, we capture the COMPLETE
 * reference schema:
 *  - augmentation-added tables (`_Versions`, `_Live`, `many_many`) are included,
 *  - the implicit `ID` column is included,
 *  - special index types (`fulltext`/`hash`/`rtree`) keep their type, because we
 *    record BEFORE the engine-specific render step.
 *
 * No `CREATE DATABASE` privilege and no temporary database are required.
 *
 * The introspection methods (`tableList`/`fieldList`/`indexList`/`hasTable`)
 * deliberately report an EMPTY database, so every requirement is treated as "to
 * create" and therefore recorded. This is essential: if they reported the real
 * database, an already-existing column or index would not be buffered, and would
 * then be falsely flagged as orphaned by the diff (and dropped under run=yes).
 *
 * Targets MySQL/MariaDB (extends MySQLSchemaManager), consistent with the rest of
 * this module.
 *
 * NOTE: developed against silverstripe/framework 5.2; the schemaUpdate /
 * requireTable internals it relies on must be verified against the target
 * project's exact framework version before trusting destructive output.
 */
class RecordingSchemaManager extends MySQLSchemaManager
{
    /**
     * Recorded reference schema, keyed by table name.
     *
     * @var array<string, array{columns: string[], indexes: array<string, mixed>}>
     */
    private array $recorded = [];

    /**
     * @return array<string, array{columns: string[], indexes: array<string, mixed>}>
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    /**
     * Intercept the table-create flush: record column names and index specs
     * instead of emitting SQL.
     */
    public function createTable($table, $fields = null, $indexes = null, $options = null, $advancedOptions = null)
    {
        $columns = $fields ? array_keys($fields) : [];

        // createTable() always adds an implicit ID primary key column. Mirror that
        // so the ID column is never seen as orphaned.
        if (!in_array('ID', $columns, true)) {
            $columns[] = 'ID';
        }

        $this->recorded[$table] = [
            'columns' => $columns,
            'indexes' => is_array($indexes) ? $indexes : [],
        ];

        // Deliberately emit no SQL.
        return $table;
    }

    /**
     * With an empty starting schema every table is a "create", so alterTable
     * should never fire. No-op for safety.
     */
    public function alterTable(
        $tableName,
        $newFields = null,
        $newIndexes = null,
        $alteredFields = null,
        $alteredIndexes = null,
        $alteredOptions = null,
        $advancedOptions = null
    ) {
        return;
    }

    /**
     * Report an empty database so every table is treated as "to create".
     */
    public function tableList()
    {
        return [];
    }

    public function fieldList($table)
    {
        return [];
    }

    public function indexList($table)
    {
        return [];
    }

    public function hasTable($table)
    {
        return false;
    }
}
