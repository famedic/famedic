<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;

class PersonNameSearch
{
    public static function fullNameSql(string $table): string
    {
        return "TRIM(CONCAT(COALESCE({$table}.name,''),' ',COALESCE({$table}.paternal_lastname,''),' ',COALESCE({$table}.maternal_lastname,'')))";
    }

    /**
     * @param  array<string>  $additionalLikeColumns
     */
    public static function apply(Builder $query, string $search, string $table, array $additionalLikeColumns = []): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        $query->where(function (Builder $query) use ($search, $table, $additionalLikeColumns) {
            self::appendConditions($query, $search, $table, $additionalLikeColumns, useOrWhere: false);
        });
    }

    /**
     * @param  array<string>  $additionalLikeColumns
     */
    public static function orApply(Builder $query, string $search, string $table, array $additionalLikeColumns = []): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        self::appendConditions($query, $search, $table, $additionalLikeColumns, useOrWhere: true);
    }

    /**
     * @param  array<string>  $additionalLikeColumns
     */
    private static function appendConditions(
        Builder $query,
        string $search,
        string $table,
        array $additionalLikeColumns,
        bool $useOrWhere,
    ): void {
        $fullNameSql = self::fullNameSql($table);
        $column = fn (string $field) => "{$table}.{$field}";
        $like = "%{$search}%";
        $where = $useOrWhere ? 'orWhere' : 'where';

        $query->{$where}($column('name'), 'LIKE', $like)
            ->orWhere($column('paternal_lastname'), 'LIKE', $like)
            ->orWhere($column('maternal_lastname'), 'LIKE', $like)
            ->orWhereRaw("{$fullNameSql} LIKE ?", [$like]);

        foreach ($additionalLikeColumns as $additionalColumn) {
            $qualifiedColumn = str_contains($additionalColumn, '.')
                ? $additionalColumn
                : $column($additionalColumn);

            $query->orWhere($qualifiedColumn, 'LIKE', $like);
        }

        self::appendMultiTermConditions($query, $search, $table, $additionalLikeColumns, $useOrWhere);
    }

    /**
     * @param  array<string>  $additionalLikeColumns
     */
    private static function appendMultiTermConditions(
        Builder $query,
        string $search,
        string $table,
        array $additionalLikeColumns,
        bool $useOrWhere,
    ): void {
        $terms = array_values(array_filter(preg_split('/\s+/u', $search) ?: []));

        if (count($terms) <= 1) {
            return;
        }

        $fullNameSql = self::fullNameSql($table);
        $column = fn (string $field) => "{$table}.{$field}";
        $groupMethod = $useOrWhere ? 'orWhere' : 'where';

        $query->{$groupMethod}(function (Builder $query) use ($terms, $table, $fullNameSql, $additionalLikeColumns, $column) {
            foreach ($terms as $term) {
                $like = '%'.$term.'%';

                $query->where(function (Builder $query) use ($like, $fullNameSql, $additionalLikeColumns, $column) {
                    $query->where($column('name'), 'LIKE', $like)
                        ->orWhere($column('paternal_lastname'), 'LIKE', $like)
                        ->orWhere($column('maternal_lastname'), 'LIKE', $like)
                        ->orWhereRaw("{$fullNameSql} LIKE ?", [$like]);

                    foreach ($additionalLikeColumns as $additionalColumn) {
                        $qualifiedColumn = str_contains($additionalColumn, '.')
                            ? $additionalColumn
                            : $column($additionalColumn);

                        $query->orWhere($qualifiedColumn, 'LIKE', $like);
                    }
                });
            }
        });
    }
}
