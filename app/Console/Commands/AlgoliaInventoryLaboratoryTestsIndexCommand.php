<?php

namespace App\Console\Commands;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use Algolia\AlgoliaSearch\SearchClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Inventario de solo lectura del índice Algolia laboratory_tests (Browse API).
 * No modifica el índice ni MySQL. Ver docs/audits/algolia-laboratory-tests-index-inventory.md
 */
class AlgoliaInventoryLaboratoryTestsIndexCommand extends Command
{
    protected $signature = 'algolia:inventory-laboratory-tests-index
                            {--index= : Nombre del índice; por defecto LaboratoryTest::searchableAs()}
                            {--output= : Ruta del informe Markdown (por defecto docs/audits/algolia-laboratory-tests-index-inventory.md)}
                            {--sample=8 : Máximo de ejemplos por categoría de objectID}
                            {--dual-sample=15 : Máximo de ids lógicos listados como duplicados (ambos formatos)}';

    protected $description = '[Solo lectura] Inventario del índice Algolia laboratory_tests (browse, clasificación objectID, marcas).';

    private const LEGACY_OBJECT_ID_PATTERN = '#^App\\\\Models\\\\LaboratoryTest::(\d+)$#';

    public function handle(): int
    {
        $driver = (string) config('scout.driver');
        if ($driver !== 'algolia') {
            $this->error('SCOUT_DRIVER debe ser algolia para este inventario.');

            return self::FAILURE;
        }

        $appId = (string) config('scout.algolia.id');
        if ($appId === '') {
            $this->error('Falta configuración: scout.algolia.id');

            return self::FAILURE;
        }

        // Solo lectura: Browse no requiere la secret key con permisos de escritura si existe Search-Only;
        // en la práctica muchos entornos usan la misma clave admin. No imprimir credenciales.
        $secret = (string) config('scout.algolia.secret');
        if ($secret === '') {
            $this->error('Falta configuración: scout.algolia.secret');

            return self::FAILURE;
        }

        $indexName = $this->resolveIndexName();
        $sampleLimit = max(0, (int) $this->option('sample'));
        $dualSampleLimit = max(0, (int) $this->option('dual-sample'));

        $expectedBrands = collect(LaboratoryBrand::cases())->map(fn (LaboratoryBrand $b) => $b->value)->all();

        $client = SearchClient::create($appId, $secret);
        $index = $client->initIndex($indexName);

        $browseOptions = [
            'attributesToRetrieve' => [
                'objectID',
                'brand',
                'gda_id',
                'name',
            ],
        ];

        $total = 0;
        $legacyCount = 0;
        $scoutNumericCount = 0;
        $otherCount = 0;

        /** @var array<int, true> */
        $logicalInLegacy = [];
        /** @var array<int, true> */
        $logicalInScout = [];

        /** @var array<string, int> */
        $brandDistribution = [];
        /** @var array<string, int> */
        $unexpectedBrandCounts = [];

        /** @var list<string> */
        $legacyExamples = [];
        /** @var list<string> */
        $scoutExamples = [];
        /** @var list<string> */
        $otherExamples = [];

        /** @var array<int, array{legacy?: string, scout?: string}> */
        $dualDetail = [];

        try {
            foreach ($index->browseObjects($browseOptions) as $hit) {
                if (! is_array($hit)) {
                    continue;
                }

                $objectId = isset($hit['objectID']) ? (string) $hit['objectID'] : '';
                $brand = isset($hit['brand']) ? (string) $hit['brand'] : '';
                $gdaId = isset($hit['gda_id']) ? (string) $hit['gda_id'] : '';
                $name = isset($hit['name']) ? (string) $hit['name'] : '';

                $total++;

                $brandKey = $brand !== '' ? $brand : '__sin_brand__';
                $brandDistribution[$brandKey] = ($brandDistribution[$brandKey] ?? 0) + 1;

                if ($brand === '' || ! in_array($brand, $expectedBrands, true)) {
                    $unexpectedKey = $brand === '' ? '__vacío__' : $brand;
                    $unexpectedBrandCounts[$unexpectedKey] = ($unexpectedBrandCounts[$unexpectedKey] ?? 0) + 1;
                }

                $label = $this->compactHitLabel($objectId, $brand, $gdaId, $name);

                if (preg_match(self::LEGACY_OBJECT_ID_PATTERN, $objectId, $m)) {
                    $legacyCount++;
                    $logicalId = (int) $m[1];
                    $logicalInLegacy[$logicalId] = true;
                    if (! isset($dualDetail[$logicalId])) {
                        $dualDetail[$logicalId] = [];
                    }
                    $dualDetail[$logicalId]['legacy'] = $objectId;
                    if (count($legacyExamples) < $sampleLimit) {
                        $legacyExamples[] = $label;
                    }
                } elseif (preg_match('/^\d+$/', $objectId)) {
                    $scoutNumericCount++;
                    $logicalId = (int) $objectId;
                    $logicalInScout[$logicalId] = true;
                    if (! isset($dualDetail[$logicalId])) {
                        $dualDetail[$logicalId] = [];
                    }
                    $dualDetail[$logicalId]['scout'] = $objectId;
                    if (count($scoutExamples) < $sampleLimit) {
                        $scoutExamples[] = $label;
                    }
                } else {
                    $otherCount++;
                    if (count($otherExamples) < $sampleLimit) {
                        $otherExamples[] = $label;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->error('Fallo al recorrer el índice: '.$e->getMessage());

            return self::FAILURE;
        }

        $dualIds = [];
        foreach ($dualDetail as $logicalId => $pair) {
            if (isset($pair['legacy'], $pair['scout'])) {
                $dualIds[$logicalId] = $pair;
            }
        }
        ksort($dualIds, SORT_NUMERIC);

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Índice', $indexName],
                ['Total documentos (browse)', number_format($total)],
                ['ObjectID legacy (App\\Models\\LaboratoryTest::{id})', number_format($legacyCount)],
                ['ObjectID Scout numérico', number_format($scoutNumericCount)],
                ['Otros formatos', number_format($otherCount)],
                ['Ids lógicos en ambos formatos', number_format(count($dualIds))],
                ['Marcas fuera de enum o vacías', number_format(count($unexpectedBrandCounts))],
            ]
        );

        $outputPath = $this->resolveOutputPath();
        $markdown = $this->buildMarkdownReport(
            indexName: $indexName,
            appIdSuffix: $this->maskAppId($appId),
            total: $total,
            legacyCount: $legacyCount,
            scoutNumericCount: $scoutNumericCount,
            otherCount: $otherCount,
            brandDistribution: $brandDistribution,
            unexpectedBrandCounts: $unexpectedBrandCounts,
            expectedBrands: $expectedBrands,
            dualIds: $dualIds,
            dualSampleLimit: $dualSampleLimit,
            legacyExamples: $legacyExamples,
            scoutExamples: $scoutExamples,
            otherExamples: $otherExamples,
        );

        File::put($outputPath, $markdown);
        $this->info('Informe escrito en: '.$outputPath);

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

        return base_path('docs/audits/algolia-laboratory-tests-index-inventory.md');
    }

    private function compactHitLabel(string $objectId, string $brand, string $gdaId, string $name): string
    {
        $snip = mb_strlen($name) > 48 ? mb_substr($name, 0, 45).'…' : $name;

        return sprintf(
            '%s | brand=%s | gda_id=%s | name=%s',
            $objectId,
            $brand !== '' ? $brand : '—',
            $gdaId !== '' ? $gdaId : '—',
            $snip !== '' ? $snip : '—'
        );
    }

    /**
     * @param  array<string, int>  $brandDistribution
     * @param  array<string, int>  $unexpectedBrandCounts
     * @param  list<string>  $expectedBrands
     * @param  array<int, array{legacy: string, scout: string}>  $dualIds
     * @param  list<string>  $legacyExamples
     * @param  list<string>  $scoutExamples
     * @param  list<string>  $otherExamples
     */
    private function buildMarkdownReport(
        string $indexName,
        string $appIdSuffix,
        int $total,
        int $legacyCount,
        int $scoutNumericCount,
        int $otherCount,
        array $brandDistribution,
        array $unexpectedBrandCounts,
        array $expectedBrands,
        array $dualIds,
        int $dualSampleLimit,
        array $legacyExamples,
        array $scoutExamples,
        array $otherExamples,
    ): string {
        $generatedAt = now()->toIso8601String();
        $env = (string) config('app.env');

        $lines = [];
        $lines[] = '# Inventario de solo lectura — índice Algolia `laboratory_tests`';
        $lines[] = '';
        $lines[] = '**Modo:** solo lectura (Browse API). No se modificó el índice ni MySQL.';
        $lines[] = '';
        $lines[] = '| Campo | Valor |';
        $lines[] = '|--------|--------|';
        $lines[] = '| Generado (ISO8601) | '.$generatedAt.' |';
        $lines[] = '| APP_ENV | '.$env.' |';
        $lines[] = '| Índice | `'.$indexName.'` |';
        $lines[] = '| Algolia App ID (enmascarado) | `'.$appIdSuffix.'` |';
        $lines[] = '| Total de documentos | **'.number_format($total).'** |';
        $lines[] = '';

        $lines[] = '## Clasificación de `objectID`';
        $lines[] = '';
        $lines[] = '| Tipo | Criterio | Cantidad |';
        $lines[] = '|------|-----------|----------|';
        $lines[] = '| Legacy | `App\\Models\\LaboratoryTest::{id}` | '.number_format($legacyCount).' |';
        $lines[] = '| Scout numérico | solo dígitos | '.number_format($scoutNumericCount).' |';
        $lines[] = '| Otros | resto | '.number_format($otherCount).' |';
        $lines[] = '';

        $lines[] = '## Ids lógicos presentes en ambos formatos (Legacy + Scout)';
        $lines[] = '';
        $lines[] = 'Mismo `id` de negocio indexado dos veces con `objectID` distinto. Cantidad: **'.number_format(count($dualIds)).'**.';
        $lines[] = '';

        if ($dualIds === []) {
            $lines[] = '*No se detectaron duplicados por id lógico entre legacy y numérico.*';
            $lines[] = '';
        } else {
            $lines[] = '| id lógico | objectID legacy | objectID numérico |';
            $lines[] = '|-----------|-----------------|-------------------|';
            $n = 0;
            foreach ($dualIds as $logicalId => $pair) {
                if ($n >= $dualSampleLimit) {
                    break;
                }
                $lines[] = '| '.$logicalId.' | `'.htmlspecialchars((string) $pair['legacy'], ENT_QUOTES, 'UTF-8').'` | `'.htmlspecialchars((string) $pair['scout'], ENT_QUOTES, 'UTF-8').'` |';
                $n++;
            }
            if (count($dualIds) > $dualSampleLimit) {
                $lines[] = '';
                $lines[] = '*Lista truncada: mostrando '.$dualSampleLimit.' de '.count($dualIds).'.*';
                $lines[] = '';
            }
        }

        $lines[] = '## Distribución por `brand` (atributo en el documento)';
        $lines[] = '';
        arsort($brandDistribution, SORT_NUMERIC);
        $lines[] = '| brand (Algolia) | Documentos |';
        $lines[] = '|-----------------|------------|';
        foreach ($brandDistribution as $b => $c) {
            $lines[] = '| `'.htmlspecialchars((string) $b, ENT_QUOTES, 'UTF-8').'` | '.number_format($c).' |';
        }
        $lines[] = '';

        $lines[] = '## Valores de marca a revisar (vacío o no están en `LaboratoryBrand`)';
        $lines[] = '';
        $lines[] = 'Valores esperados: `'.implode('`, `', $expectedBrands).'`. Clave `__vacío__` = atributo ausente o string vacío en el documento.';
        $lines[] = '';

        if ($unexpectedBrandCounts === []) {
            $lines[] = '*Ningún documento en esta categoría.*';
            $lines[] = '';
        } else {
            arsort($unexpectedBrandCounts, SORT_NUMERIC);
            $lines[] = '| brand (inesperado) | Documentos |';
            $lines[] = '|--------------------|------------|';
            foreach ($unexpectedBrandCounts as $b => $c) {
                $lines[] = '| `'.htmlspecialchars((string) $b, ENT_QUOTES, 'UTF-8').'` | '.number_format($c).' |';
            }
            $lines[] = '';
        }

        $lines[] = '## Ejemplos representativos (primeros registros por categoría)';
        $lines[] = '';

        $lines[] = '### Legacy';
        $lines[] = $this->bulletExamples($legacyExamples);

        $lines[] = '### Scout numérico';
        $lines[] = $this->bulletExamples($scoutExamples);

        $lines[] = '### Otros';
        $lines[] = $this->bulletExamples($otherExamples);

        $lines[] = '## Comando utilizado';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = 'php artisan algolia:inventory-laboratory-tests-index';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Opciones: `--index=`, `--output=`, `--sample=N`, `--dual-sample=M`.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $examples
     */
    private function bulletExamples(array $examples): string
    {
        if ($examples === []) {
            return "\n*Sin ejemplos (vacío o límite de muestra 0).*\n";
        }

        $out = '';
        foreach ($examples as $ex) {
            $out .= '- '.htmlspecialchars($ex, ENT_QUOTES, 'UTF-8')."\n";
        }

        return "\n".$out;
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
