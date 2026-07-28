<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Asserts a minimum structural contract for an already-existing migration target.
 *
 * Uses Schema Builder introspection (MySQL + SQLite). Does not alter schema.
 */
final class MinimumTableContract
{
    /**
     * @param  array{
     *     columns: array<string, array{types: list<string>, nullable?: bool|null}>,
     *     indexes?: list<array{name: string, columns: list<string>, unique?: bool}>,
     *     foreign_keys?: list<array{
     *         columns: list<string>,
     *         referenced_table: string,
     *         referenced_columns: list<string>,
     *         on_delete?: string|list<string>
     *     }>
     * }  $contract
     */
    public static function assertCompatible(string $table, array $contract): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException(
                "Migration contract check failed: table [{$table}] does not exist."
            );
        }

        $columnsByName = [];
        foreach (Schema::getColumns($table) as $column) {
            $columnsByName[(string) $column['name']] = $column;
        }

        foreach ($contract['columns'] as $name => $rules) {
            if (! isset($columnsByName[$name])) {
                throw new RuntimeException(
                    "Incompatible table [{$table}]: missing column [{$name}]; "
                    .'expected types ['.implode('|', $rules['types']).']'
                    .(array_key_exists('nullable', $rules) && $rules['nullable'] !== null
                        ? ', nullable='.($rules['nullable'] ? 'true' : 'false')
                        : '')
                    .'.'
                );
            }

            $observed = $columnsByName[$name];
            $observedType = strtolower((string) ($observed['type_name'] ?? $observed['type'] ?? ''));
            $allowed = array_map('strtolower', $rules['types']);

            if (! self::typeMatches($observedType, $allowed)) {
                throw new RuntimeException(
                    "Incompatible table [{$table}]: column [{$name}] type mismatch; "
                    .'expected one of ['.implode('|', $rules['types']).'], '
                    ."observed [{$observedType}]."
                );
            }

            if (array_key_exists('nullable', $rules) && $rules['nullable'] !== null) {
                $observedNullable = (bool) ($observed['nullable'] ?? false);
                if ($observedNullable !== $rules['nullable']) {
                    throw new RuntimeException(
                        "Incompatible table [{$table}]: column [{$name}] nullability mismatch; "
                        .'expected nullable='.($rules['nullable'] ? 'true' : 'false').', '
                        .'observed nullable='.($observedNullable ? 'true' : 'false').'.'
                    );
                }
            }
        }

        $indexes = Schema::getIndexes($table);
        foreach ($contract['indexes'] ?? [] as $expectedIndex) {
            self::assertIndex($table, $indexes, $expectedIndex);
        }

        $foreignKeys = Schema::getForeignKeys($table);
        foreach ($contract['foreign_keys'] ?? [] as $expectedFk) {
            self::assertForeignKey($table, $foreignKeys, $expectedFk);
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function typeMatches(string $observedType, array $allowed): bool
    {
        $observedType = preg_replace('/\s+/', '', $observedType) ?? $observedType;

        foreach ($allowed as $candidate) {
            $candidate = preg_replace('/\s+/', '', $candidate) ?? $candidate;
            if ($observedType === $candidate) {
                return true;
            }
            if (str_starts_with($observedType, $candidate)) {
                return true;
            }
            // SQLite often reports varchar without length; MySQL may report bigint for foreignId.
            if ($candidate === 'string' && in_array($observedType, ['varchar', 'char', 'text', 'clob', 'string'], true)) {
                return true;
            }
            if ($candidate === 'uuid' && in_array($observedType, ['uuid', 'guid', 'varchar', 'char', 'string'], true)) {
                return true;
            }
            if ($candidate === 'json' && in_array($observedType, ['json', 'text', 'clob'], true)) {
                return true;
            }
            if ($candidate === 'boolean' && in_array($observedType, ['boolean', 'bool', 'tinyint', 'integer'], true)) {
                return true;
            }
            if (
                in_array($candidate, ['bigint', 'integer'], true)
                && in_array($observedType, ['bigint', 'integer', 'int', 'integer unsigned', 'bigint unsigned'], true)
            ) {
                return true;
            }
            if (
                in_array($candidate, ['timestamp', 'datetime'], true)
                && in_array($observedType, ['timestamp', 'datetime'], true)
            ) {
                return true;
            }
            if (
                in_array($candidate, ['text', 'longtext'], true)
                && in_array($observedType, ['text', 'longtext', 'clob', 'varchar'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $indexes
     * @param  array{name: string, columns: list<string>, unique?: bool}  $expected
     */
    private static function assertIndex(string $table, array $indexes, array $expected): void
    {
        $wantUnique = (bool) ($expected['unique'] ?? false);
        $wantColumns = array_values($expected['columns']);

        foreach ($indexes as $index) {
            $name = (string) ($index['name'] ?? '');
            $cols = array_values($index['columns'] ?? []);
            $unique = (bool) ($index['unique'] ?? false);

            $nameMatch = $name === $expected['name'];
            $colsMatch = $cols === $wantColumns;
            if (! $nameMatch && ! $colsMatch) {
                continue;
            }

            if ($wantUnique && ! $unique && ! ($index['primary'] ?? false)) {
                throw new RuntimeException(
                    "Incompatible table [{$table}]: index [{$expected['name']}] expected unique; "
                    .'observed non-unique'
                    .($name !== '' ? " name [{$name}]" : '')
                    .' columns ['.implode(',', $cols).'].'
                );
            }

            if ($colsMatch || $nameMatch) {
                if ($colsMatch || $cols === $wantColumns) {
                    return;
                }

                throw new RuntimeException(
                    "Incompatible table [{$table}]: index [{$expected['name']}] column mismatch; "
                    .'expected ['.implode(',', $wantColumns).'], '
                    .'observed ['.implode(',', $cols).'].'
                );
            }
        }

        throw new RuntimeException(
            "Incompatible table [{$table}]: missing index [{$expected['name']}] "
            .'on columns ['.implode(',', $wantColumns).']'
            .($wantUnique ? ' (unique)' : '')
            .'.'
        );
    }

    /**
     * @param  list<array<string, mixed>>  $foreignKeys
     * @param  array{
     *     columns: list<string>,
     *     referenced_table: string,
     *     referenced_columns: list<string>,
     *     on_delete?: string|list<string>
     * }  $expected
     */
    private static function assertForeignKey(string $table, array $foreignKeys, array $expected): void
    {
        $wantColumns = array_values($expected['columns']);
        $wantRefTable = $expected['referenced_table'];
        $wantRefColumns = array_values($expected['referenced_columns']);
        $allowedOnDelete = array_map(
            static fn (string $v): string => strtolower(str_replace('_', ' ', $v)),
            (array) ($expected['on_delete'] ?? [])
        );

        foreach ($foreignKeys as $fk) {
            $cols = array_values($fk['columns'] ?? []);
            $refTable = (string) ($fk['foreign_table'] ?? '');
            $refCols = array_values($fk['foreign_columns'] ?? []);
            $onDelete = strtolower(str_replace('_', ' ', (string) ($fk['on_delete'] ?? '')));

            if ($cols !== $wantColumns) {
                continue;
            }

            if ($refTable !== $wantRefTable || $refCols !== $wantRefColumns) {
                throw new RuntimeException(
                    "Incompatible table [{$table}]: foreign key on [".implode(',', $wantColumns).'] '
                    ."expected references {$wantRefTable}(".implode(',', $wantRefColumns).'), '
                    ."observed {$refTable}(".implode(',', $refCols).').'
                );
            }

            if ($allowedOnDelete !== [] && ! in_array($onDelete, $allowedOnDelete, true)) {
                throw new RuntimeException(
                    "Incompatible table [{$table}]: foreign key on [".implode(',', $wantColumns).'] '
                    .'ON DELETE expected one of ['.implode('|', $allowedOnDelete).'], '
                    ."observed [{$onDelete}]."
                );
            }

            return;
        }

        throw new RuntimeException(
            "Incompatible table [{$table}]: missing foreign key on [".implode(',', $wantColumns).'] '
            ."referencing {$wantRefTable}(".implode(',', $wantRefColumns).').'
        );
    }
}
