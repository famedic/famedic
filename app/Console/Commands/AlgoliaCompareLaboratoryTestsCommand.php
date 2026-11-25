<?php

namespace App\Console\Commands;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use Algolia\AlgoliaSearch\SearchClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Comparación de solo lectura: IDs de LaboratoryTest en MySQL vs objectID en Algolia (Browse).
 * No modifica Algolia ni MySQL. Ver docs/audits/algolia-laboratory-tests-mysql-diff.md
 */
class AlgoliaCompareLaboratoryTestsCommand extends Command
{
    protected $signature = 'algolia:compare-laboratory-tests
                            {--index= : Nombre del índice; por defecto LaboratoryTest::searchableAs()}
                            {--output= : Ruta del informe Markdown (por defecto docs/audits/algolia-laboratory-tests-mysql-diff.md)}';

    protected $description = '[Solo lectura] Compara LaboratoryTest (MySQL con soft deletes) vs índice Algolia laboratory_tests.';

    private const LEGACY_OBJECT_ID_PATTERN = '#^App\\\\Models\\\\LaboratoryTest::(\d+)$#';

    public function handle(): int
    {
        $driver = (string) config('scout.driver');
        if ($driver !== 'algolia') {
            $this->error('SCOUT_DRIVER debe ser algolia para esta comparación.');

            return self::FAILURE;
        }

        $appId = (string) config('scout.algolia.id');
        $secret = (string) config('scout.algolia.secret');
        if ($appId === '' || $secret === '') {
            $this->error('Falta configuración: scout.algolia.id o scout.algolia.secret');

            return self::FAILURE;
        }

        $indexName = $this->resolveIndexName();

        $mysqlRows = LaboratoryTest::withTrashed()
            ->select(['id', 'name', 'brand', 'gda_id', 'deleted_at'])
            ->orderBy('id')
            ->get();

        /** @var array<int, object{id: int, name: string|null, brand: mixed, gda_id: string|null, deleted_at: mixed}> */
        $mysqlById = [];
        /** @var array<int, true> */
        $mysqlAllIds = [];
        /** @var array<int, true> */
        $mysqlActiveIds = [];
        /** @var array<int, true> */
        $mysqlDeletedIds = [];

        foreach ($mysqlRows as $row) {
            $id = (int) $row->id;
            $mysqlById[$id] = $row;
            $mysqlAllIds[$id] = true;
            if ($row->deleted_at === null) {
                $mysqlActiveIds[$id] = true;
            } else {
                $mysqlDeletedIds[$id] = true;
            }
        }

        $client = SearchClient::create($appId, $secret);
        $index = $client->initIndex($indexName);

        $browseOptions = [
            'attributesToRetrieve' => [
                'objectID',
            ],
        ];

        /** @var array<int, list<string>> logical id => objectID strings seen */
        $algoliaObjectIdsByLogicalId = [];
        /** @var list<string> */
        $nonParsableObjectIds = [];

        $algoliaDocCount = 0;

        try {
            foreach ($index->browseObjects($browseOptions) as $hit) {
                if (! is_array($hit)) {
                    continue;
                }

                $objectId = isset($hit['objectID']) ? (string) $hit['objectID'] : '';
                if ($objectId === '') {
                    continue;
                }

                $algoliaDocCount++;

                $logicalId = $this->parseLogicalIdFromObjectId($objectId);

                if ($logicalId === null) {
                    $nonParsableObjectIds[] = $objectId;

                    continue;
                }

                if (! isset($algoliaObjectIdsByLogicalId[$logicalId])) {
                    $algoliaObjectIdsByLogicalId[$logicalId] = [];
                }
                $algoliaObjectIdsByLogicalId[$logicalId][] = $objectId;
            }
        } catch (Throwable $e) {
            $this->error('Fallo al recorrer el índice Algolia: '.$e->getMessage());

            return self::FAILURE;
        }

        $algoliaLogicalIds = array_fill_keys(array_keys($algoliaObjectIdsByLogicalId), true);

        $inBothIds = array_intersect_key($mysqlAllIds, $algoliaLogicalIds);
        $algoliaNotMysqlIds = array_diff_key($algoliaLogicalIds, $mysqlAllIds);
        $softDeletedStillInAlgoliaIds = array_intersect_key($mysqlDeletedIds, $algoliaLogicalIds);
        $activeMissingFromAlgoliaIds = array_diff_key($mysqlActiveIds, $algoliaLogicalIds);

        ksort($inBothIds, SORT_NUMERIC);
        ksort($algoliaNotMysqlIds, SORT_NUMERIC);
        ksort($softDeletedStillInAlgoliaIds, SORT_NUMERIC);
        ksort($activeMissingFromAlgoliaIds, SORT_NUMERIC);

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Índice Algolia', $indexName],
                ['Filas MySQL (con soft deleted)', number_format(count($mysqlAllIds))],
                ['Documentos Algolia (browse)', number_format($algoliaDocCount)],
                ['Ids lógicos Algolia (parseados)', number_format(count($algoliaLogicalIds))],
                ['ObjectID no parseables', number_format(count($nonParsableObjectIds))],
                ['En ambos (MySQL ∪ soft + Algolia)', number_format(count($inBothIds))],
                ['En Algolia, ausentes en MySQL', number_format(count($algoliaNotMysqlIds))],
                ['Soft deleted en MySQL y aún en Algolia', number_format(count($softDeletedStillInAlgoliaIds))],
                ['Activos en MySQL ausentes en Algolia', number_format(count($activeMissingFromAlgoliaIds))],
            ]
        );

        $outputPath = $this->resolveOutputPath();
        $markdown = $this->buildMarkdownReport(
            indexName: $indexName,
            appIdMasked: $this->maskAppId($appId),
            mysqlTotal: count($mysqlAllIds),
            algoliaDocCount: $algoliaDocCount,
            algoliaLogicalCount: count($algoliaLogicalIds),
            nonParsableObjectIds: $nonParsableObjectIds,
            inBothIds: array_keys($inBothIds),
            algoliaNotMysqlIds: array_keys($algoliaNotMysqlIds),
            softDeletedStillInAlgoliaIds: array_keys($softDeletedStillInAlgoliaIds),
            activeMissingFromAlgoliaIds: array_keys($activeMissingFromAlgoliaIds),
            algoliaObjectIdsByLogicalId: $algoliaObjectIdsByLogicalId,
            mysqlById: $mysqlById,
        );

        File::put($outputPath, $markdown);
        $this->info('Informe escrito en: '.$outputPath);

        $this->newLine();
        $this->warn('Ejecución de solo lectura finalizada. No se modificó Algolia ni MySQL. Esperando autorización para pasos posteriores.');

        return self::SUCCESS;
    }

    private function resolveIndexName(): string
    {
        $explicit = $this->option('index');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return (new LaboratoryTest)->searchableAs();
    }

    private function resolveOutputPath(): string
    {
        $path = $this->option('output');

        if (is_string($path) && $path !== '') {
            return base_path($path);
        }

        return base_path('docs/audits/algolia-laboratory-tests-mysql-diff.md');
    }

    private function parseLogicalIdFromObjectId(string $objectId): ?int
    {
        if (preg_match(self::LEGACY_OBJECT_ID_PATTERN, $objectId, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/^\d+$/', $objectId)) {
            return (int) $objectId;
        }

        return null;
    }

    private function expectedLegacyObjectId(int $id): string
    {
        return LaboratoryTest::class.'::'.$id;
    }

    /**
     * @param  array<int, list<string>>  $algoliaObjectIdsByLogicalId
     * @param  array<int, object>  $mysqlById
     */
    private function buildMarkdownReport(
        string $indexName,
        string $appIdMasked,
        int $mysqlTotal,
        int $algoliaDocCount,
        int $algoliaLogicalCount,
        array $nonParsableObjectIds,
        array $inBothIds,
        array $algoliaNotMysqlIds,
        array $softDeletedStillInAlgoliaIds,
        array $activeMissingFromAlgoliaIds,
        array $algoliaObjectIdsByLogicalId,
        array $mysqlById,
    ): string {
        $generatedAt = now()->toIso8601String();
        $env = (string) config('app.env');

        $lines = [];
        $lines[] = '# Diferencias MySQL vs Algolia — `laboratory_tests` / `LaboratoryTest`';
        $lines[] = '';
        $lines[] = '**Modo:** solo lectura. No se modificó Algolia ni MySQL.';
        $lines[] = '';
        $lines[] = '| Campo | Valor |';
        $lines[] = '|--------|--------|';
        $lines[] = '| Generado (ISO8601) | '.$generatedAt.' |';
        $lines[] = '| APP_ENV | '.$env.' |';
        $lines[] = '| Índice Algolia | `'.htmlspecialchars($indexName, ENT_QUOTES, 'UTF-8').'` |';
        $lines[] = '| Algolia App ID (enmascarado) | `'.htmlspecialchars($appIdMasked, ENT_QUOTES, 'UTF-8').'` |';
        $lines[] = '| Filas MySQL (`withTrashed`) | **'.number_format($mysqlTotal).'** |';
        $lines[] = '| Documentos Algolia (browse) | **'.number_format($algoliaDocCount).'** |';
        $lines[] = '| Ids lógicos únicos (desde `objectID`) | **'.number_format($algoliaLogicalCount).'** |';
        $lines[] = '';

        $lines[] = '## Resumen de conjuntos';
        $lines[] = '';
        $lines[] = '| Categoría | Cantidad |';
        $lines[] = '|-----------|----------|';
        $lines[] = '| IDs en MySQL (incl. soft deleted) y en Algolia | '.number_format(count($inBothIds)).' |';
        $lines[] = '| IDs en Algolia sin fila en MySQL | '.number_format(count($algoliaNotMysqlIds)).' |';
        $lines[] = '| Soft deleted en MySQL que siguen indexados en Algolia | '.number_format(count($softDeletedStillInAlgoliaIds)).' |';
        $lines[] = '| Activos en MySQL ausentes en Algolia | '.number_format(count($activeMissingFromAlgoliaIds)).' |';
        $lines[] = '| `objectID` no reconocidos (ni legacy ni numérico) | '.number_format(count($nonParsableObjectIds)).' |';
        $lines[] = '';

        if ($nonParsableObjectIds !== []) {
            $lines[] = '## ObjectID no parseables (ni `App\\\\Models\\\\LaboratoryTest::{id}` ni solo dígitos)';
            $lines[] = '';
            $lines[] = '| objectID completo |';
            $lines[] = '|-------------------|';
            foreach ($nonParsableObjectIds as $oid) {
                $lines[] = '| `'.htmlspecialchars($oid, ENT_QUOTES, 'UTF-8').'` |';
            }
            $lines[] = '';
        }

        $lines[] = '## 1. IDs presentes en MySQL y en Algolia';
        $lines[] = '';
        $lines[] = $this->mysqlAlgoliaTable(
            ids: $inBothIds,
            mysqlById: $mysqlById,
            algoliaObjectIdsByLogicalId: $algoliaObjectIdsByLogicalId,
            includeMysql: true,
        );

        $lines[] = '## 2. Presentes en Algolia, ausentes en MySQL';
        $lines[] = '';
        $lines[] = '*No hay fila en `laboratory_tests` (ni activa ni soft deleted) para estos ids lógicos.*';
        $lines[] = '';
        $lines[] = $this->mysqlAlgoliaTable(
            ids: $algoliaNotMysqlIds,
            mysqlById: $mysqlById,
            algoliaObjectIdsByLogicalId: $algoliaObjectIdsByLogicalId,
            includeMysql: false,
        );

        $lines[] = '## 3. Soft deleted en MySQL que siguen en Algolia';
        $lines[] = '';
        $lines[] = $this->mysqlAlgoliaTable(
            ids: $softDeletedStillInAlgoliaIds,
            mysqlById: $mysqlById,
            algoliaObjectIdsByLogicalId: $algoliaObjectIdsByLogicalId,
            includeMysql: true,
        );

        $lines[] = '## 4. Activos en MySQL ausentes en Algolia';
        $lines[] = '';
        $lines[] = '*`objectID` esperado en formato legacy usado por el proyecto.*';
        $lines[] = '';
        $lines[] = $this->activeMissingTable(
            ids: $activeMissingFromAlgoliaIds,
            mysqlById: $mysqlById,
        );

        $lines[] = '## Comando utilizado';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = 'php artisan algolia:compare-laboratory-tests';
        $lines[] = '```';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, object>  $mysqlById
     * @param  array<int, list<string>>  $algoliaObjectIdsByLogicalId
     */
    private function mysqlAlgoliaTable(
        array $ids,
        array $mysqlById,
        array $algoliaObjectIdsByLogicalId,
        bool $includeMysql,
    ): string {
        if ($ids === []) {
            return "\n*Ninguno.*\n\n";
        }

        $lines = [];
        $lines[] = '| id | objectID(s) en Algolia | nombre (MySQL) | marca (MySQL) | gda_id (MySQL) | deleted_at (MySQL) |';
        $lines[] = '|----|------------------------|----------------|---------------|----------------|-------------------|';

        foreach ($ids as $id) {
            $oids = $algoliaObjectIdsByLogicalId[$id] ?? [];
            $oidsStr = $oids === [] ? '—' : implode(', ', array_map(fn (string $o) => '`'.htmlspecialchars($o, ENT_QUOTES, 'UTF-8').'`', $oids));

            if ($includeMysql && isset($mysqlById[$id])) {
                $row = $mysqlById[$id];
                $lines[] = sprintf(
                    '| %d | %s | %s | %s | %s | %s |',
                    $id,
                    $oidsStr,
                    $this->cellText($row->name ?? ''),
                    $this->brandCell($row->brand ?? null),
                    $this->cellText((string) ($row->gda_id ?? '')),
                    $row->deleted_at ? htmlspecialchars((string) $row->deleted_at, ENT_QUOTES, 'UTF-8') : '—'
                );
            } else {
                $lines[] = sprintf(
                    '| %d | %s | — | — | — | — |',
                    $id,
                    $oidsStr
                );
            }
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, object>  $mysqlById
     */
    private function activeMissingTable(array $ids, array $mysqlById): string
    {
        if ($ids === []) {
            return "\n*Ninguno.*\n\n";
        }

        $lines = [];
        $lines[] = '| id | objectID esperado (legacy) | nombre | marca | gda_id |';
        $lines[] = '|----|---------------------------|--------|-------|--------|';

        foreach ($ids as $id) {
            $row = $mysqlById[$id] ?? null;
            $expectedOid = '`'.htmlspecialchars($this->expectedLegacyObjectId($id), ENT_QUOTES, 'UTF-8').'`';

            if ($row === null) {
                $lines[] = sprintf('| %d | %s | — | — | — |', $id, $expectedOid);

                continue;
            }

            $lines[] = sprintf(
                '| %d | %s | %s | %s | %s |',
                $id,
                $expectedOid,
                $this->cellText($row->name ?? ''),
                $this->brandCell($row->brand ?? null),
                $this->cellText((string) ($row->gda_id ?? '')),
            );
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function brandCell(mixed $brand): string
    {
        if ($brand === null || $brand === '') {
            return '—';
        }

        if (is_string($brand)) {
            return $this->cellText($brand);
        }

        // LaboratoryBrand es BackedEnum string; priorizar tipo concreto del modelo.
        if ($brand instanceof LaboratoryBrand) {
            return $this->cellText($brand->value);
        }

        if ($brand instanceof \BackedEnum) {
            return $this->cellText((string) $brand->value);
        }

        if ($brand instanceof \UnitEnum) {
            return $this->cellText($brand->name);
        }

        if (is_scalar($brand)) {
            return $this->cellText((string) $brand);
        }

        return '—';
    }

    private function cellText(string $value): string
    {
        $t = str_replace(["\r\n", "\r", "\n"], ' ', $value);

        return htmlspecialchars(mb_strlen($t) > 120 ? mb_substr($t, 0, 117).'…' : $t, ENT_QUOTES, 'UTF-8');
    }

    private function maskAppId(string $appId): string
    {
        $len = strlen($appId);
        if ($len <= 4) {
            return '****';
        }

        return str_repeat('*', $len - 4).substr($appId, -4);
    }
}
