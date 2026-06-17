<?php

namespace Sweeper\Schema;

/**
 * Pure schema-diff helpers. No database or framework dependencies, so unit
 * testable in isolation.
 *
 * Uniform schema format used for both the "current" (live) and "clean"
 * (reference) schema:
 *
 * [
 *   'TableName' => [
 *      'columns' => ['ID', 'ClassName', ...],
 *      'indexes' => [
 *          ['name' => 'URLSegment', 'columns' => ['URLSegment'], 'type' => 'unique'],
 *          ...
 *      ],
 *   ],
 * ]
 *
 * Tables and columns are compared by name (engine-independent). Indexes are
 * compared by SIGNATURE (type + columns), never by name, because index names may
 * differ between engines while the column composition does not.
 */
class SchemaDiff
{
    /**
     * Confirmation token for a droppable set: a short hash over the canonicalised
     * diff. Binding run=yes to this token forces a prior dry-run review, and
     * refuses execution when the droppable set changed between the review and the
     * destructive run (deploy, dev/build, manual schema change).
     */
    public static function confirmationToken(array $droppable): string
    {
        $tables = $droppable['tables'] ?? [];
        $columns = $droppable['columns'] ?? [];
        $indexes = $droppable['indexes'] ?? [];

        sort($tables);
        ksort($columns);
        foreach ($columns as &$cols) {
            sort($cols);
        }
        unset($cols);
        ksort($indexes);
        foreach ($indexes as &$idxs) {
            sort($idxs);
        }
        unset($idxs);

        $canonical = ['tables' => $tables, 'columns' => $columns, 'indexes' => $indexes];

        return substr(hash('sha256', json_encode($canonical)), 0, 10);
    }

    /**
     * Engine-independent index signature: type + alphabetically sorted columns.
     */
    public static function indexSignature(array $columns, string $type): string
    {
        $cols = $columns;
        sort($cols);
        return strtolower($type) . ':' . implode(',', $cols);
    }

    /**
     * @param array<string, array{columns: string[], indexes: array}> $current
     * @param array<string, array{columns: string[], indexes: array}> $clean
     * @return array{tables: string[], columns: array<string,string[]>, indexes: array<string,string[]>}
     */
    public static function diff(array $current, array $clean): array
    {
        $result = ['tables' => [], 'columns' => [], 'indexes' => []];

        // Case-insensitive table lookup. MySQL with lower_case_table_names=1
        // returns lowercase table names, while the recorded reference schema uses
        // the class table-name case. Comparing case-sensitively would flag every
        // table as orphaned.
        $cleanByLower = [];
        foreach ($clean as $cleanTable => $cleanInfo) {
            $cleanByLower[strtolower($cleanTable)] = $cleanInfo;
        }

        foreach ($current as $table => $info) {
            $cleanInfo = $cleanByLower[strtolower($table)] ?? null;

            // Whole table absent from the clean schema -> droppable.
            if ($cleanInfo === null) {
                $result['tables'][] = $table;
                continue;
            }

            // Columns present now but not in the clean schema.
            $orphanColumns = array_values(array_diff(
                $info['columns'] ?? [],
                $cleanInfo['columns'] ?? []
            ));
            if ($orphanColumns) {
                $result['columns'][$table] = $orphanColumns;
            }

            // Indexes: compare by signature, never by name.
            $cleanSignatures = [];
            foreach ($cleanInfo['indexes'] ?? [] as $idx) {
                $signature = SchemaDiff::indexSignature($idx['columns'] ?? [], $idx['type'] ?? 'index');
                $cleanSignatures[$signature] = true;
            }

            $orphanIndexes = [];
            foreach ($info['indexes'] ?? [] as $idx) {
                $name = (string)($idx['name'] ?? '');

                // Never drop the primary key.
                if (strtoupper($name) === 'PRIMARY') {
                    continue;
                }

                $signature = SchemaDiff::indexSignature($idx['columns'] ?? [], $idx['type'] ?? 'index');
                if (!isset($cleanSignatures[$signature])) {
                    $orphanIndexes[] = $name;
                }
            }
            if ($orphanIndexes) {
                $result['indexes'][$table] = $orphanIndexes;
            }
        }

        return $result;
    }
}
