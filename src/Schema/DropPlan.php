<?php

namespace Sweeper\Schema;

/**
 * Turns a droppable set (from SchemaDiff::diff) into an ordered list of DDL
 * statements that is safe to execute against MySQL/MariaDB.
 *
 * Pure: no database or framework dependencies, so unit testable in isolation.
 */
class DropPlan
{
    /**
     * @param array{tables?: string[], indexes?: array<string,string[]>, columns?: array<string,string[]>} $droppable
     * @return string[] Ordered DDL statements.
     */
    public static function statements(array $droppable): array
    {
        $statements = [];

        // Whole tables go first: a DROP TABLE removes the table's columns and
        // indexes in one step, and diff() never also lists a dropped table's
        // columns/indexes separately.
        foreach ($droppable['tables'] ?? [] as $table) {
            $statements[] = "DROP TABLE IF EXISTS \"$table\"";
        }

        // Indexes before columns: dropping a column that is the last member of
        // an index makes MySQL drop that index implicitly, so a later explicit
        // DROP INDEX would fail with error 1091 ("check that column/key exists").
        foreach ($droppable['indexes'] ?? [] as $table => $indexes) {
            foreach ($indexes as $index) {
                if (strtoupper((string)$index) === 'PRIMARY') {
                    continue;
                }
                $statements[] = "DROP INDEX \"$index\" ON \"$table\"";
            }
        }

        foreach ($droppable['columns'] ?? [] as $table => $columns) {
            foreach ($columns as $column) {
                $statements[] = "ALTER TABLE \"$table\" DROP COLUMN \"$column\"";
            }
        }

        return $statements;
    }
}
