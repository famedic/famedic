<?php

namespace App\Console\Commands;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use Algolia\AlgoliaSearch\SearchClient;
use Algolia\AlgoliaSearch\SearchIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Comando temporal: subir a Algolia los documentos de laboratory_tests desde la BD actual,
 * en lotes síncronos (sin jobs), sin vaciar el índice ni borrar huérfanos.
 *
 * Ejecutar solo tras autorización explícita. Revisar docs/audits/algolia-laboratory-tests-recovery.md
 */
class AlgoliaRecoverLaboratoryTestsCommand extends Command
{
    protected $signature = 'algolia:recover-laboratory-tests
                            {--dry-run : Solo validaciones, conteos y vista previa de un lote; no llama a Algolia}
                            {--execute : Envía objetos a Algolia por lotes (requiere confirmación salvo --force)}
                            {--chunk=250 : Registros por lote}
                            {--object-id-format=scout : scout = id numérico (Scout estándar); legacy = App\\Models\\LaboratoryTest::{id} (como algolia:clear-laboratory-test-elements)}
                            {--force : Con --execute, no pedir confirmación interactiva}';

    protected $description = '[TEMPORAL] Recupera documentos del índice Algolia laboratory_tests desde MySQL (lotes síncronos, sin jobs).';

    private const EXPECTED_INDEX = 'laboratory_tests';

    /** @var 'scout'|'legacy' */
    private string $objectIdFormat = 'scout';

    public function handle(): int
    {
        if (! $this->option('dry-run') && ! $this->option('execute')) {
            $this->error('Indica --dry-run (simulación) o --execute (envío real a Algolia). No se ejecuta nada por defecto.');

            return self::FAILURE;
        }

        $env = (string) config('app.env');
        if ($env !== 'production') {
            $this->warn("APP_ENV actual: {$env} (no es \"production\"). Comprueba que la BD y las credenciales Algolia sean las correctas.");
        }

        $driver = (string) config('scout.driver');
        if ($driver !== 'algolia') {
            $this->error("SCOUT_DRIVER debe ser \"algolia\"; actual: {$driver}");

            return self::FAILURE;
        }

        $model = new LaboratoryTest;
        $indexName = $model->searchableAs();

        if ($indexName !== self::EXPECTED_INDEX) {
            $this->error("Índice destino esperado \"".self::EXPECTED_INDEX.'"; actual: '.$indexName.' (revisa SCOUT_PREFIX).');

            return self::FAILURE;
        }

        $appId = (string) config('scout.algolia.id');
        $secret = (string) config('scout.algolia.secret');
        if ($appId === '' || $secret === '') {
            $this->error('Faltan scout.algolia.id / scout.algolia.secret (ALGOLIA_APP_ID / ALGOLIA_SECRET).');

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));

        $formatOption = (string) $this->option('object-id-format');
        if ($formatOption === 'legacy') {
            $this->objectIdFormat = 'legacy';
        } elseif ($formatOption === 'scout') {
            $this->objectIdFormat = 'scout';
        } else {
            $this->error('object-id-format debe ser "scout" o "legacy".');

            return self::FAILURE;
        }

        $filterQuery = LaboratoryTest::query()->whereNull('deleted_at');

        $eligible = (clone $filterQuery)->count();

        $this->info('Índice Algolia: '.$indexName);
        $this->line('Formato objectID: '.$this->objectIdFormat.' (opción --object-id-format)');
        $this->info('Registros elegibles (deleted_at IS NULL): '.number_format($eligible));

        // Agrupación propia (evita mezclar orderBy(id) del query de listado con GROUP BY → ONLY_FULL_GROUP_BY).
        $byBrand = (clone $filterQuery)
            ->selectRaw('brand, COUNT(*) as c')
            ->groupBy('brand')
            ->orderBy('brand')
            ->pluck('c', 'brand')
            ->all();

        $this->newLine();
        $this->line('Cantidad por marca (enum):');
        foreach (LaboratoryBrand::cases() as $brand) {
            $key = $brand->value;
            $n = (int) ($byBrand[$key] ?? 0);
            $this->line(sprintf('  %-12s %s', $key, number_format($n)));
        }
        $this->newLine();

        $baseQuery = (clone $filterQuery)
            ->with(['laboratoryTestCategory:id,name'])
            ->orderBy('id');

        if ($this->option('dry-run')) {
            $this->dryRunPreview($baseQuery, $chunk);

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Se enviarán '.number_format($eligible).' registros a Algolia en lotes de '.$chunk.'. ¿Continuar?', false)) {
            $this->comment('Operación cancelada.');

            return self::SUCCESS;
        }

        return $this->runExecute($baseQuery, $chunk, $appId, $secret, $indexName, $eligible);
    }

    private function dryRunPreview($baseQuery, int $chunk): void
    {
        $this->comment('MODO SIMULACIÓN — no se envía nada a Algolia.');

        $batch = (clone $baseQuery)->limit($chunk)->get();
        if ($batch->isEmpty()) {
            $this->warn('No hay filas para previsualizar.');

            return;
        }

        $objects = $this->buildAlgoliaObjects($batch);
        $this->line('Primer lote: '.count($objects).' objeto(s). Ejemplo (primer objeto, sin secretos):');
        $sample = $objects[0] ?? [];
        $this->line(json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $id85 = $batch->firstWhere('id', 85);
        if ($id85) {
            $this->newLine();
            $this->line('Validación ID 85 (MySQL actual):');
            $this->line('  objectID (comando): '.$this->scoutObjectId($id85));
            $this->line('  objectID Scout nativo sería: '.(string) $id85->getScoutKey());
            $this->line('  brand: '.$id85->brand->value);
            $this->line('  gda_id: '.$id85->gda_id);
        } else {
            $this->newLine();
            $this->comment('El id 85 no está en el primer lote; en --execute se validará al final si existe en BD.');
        }
    }

    private function runExecute(
        $baseQuery,
        int $chunk,
        string $appId,
        string $secret,
        string $indexName,
        int $eligible,
    ): int {
        $client = SearchClient::create($appId, $secret);
        $index = $client->initIndex($indexName);

        $processed = 0;
        $errors = 0;
        /** @var list<string> $errorMessages */
        $errorMessages = [];

        $baseQuery->clone()->chunkById($chunk, function (Collection $models) use ($index, &$processed, &$errors, &$errorMessages) {
            $objects = $this->buildAlgoliaObjects($models);

            if ($objects === []) {
                return;
            }

            $firstId = $models->first()->getKey();
            $lastId = $models->last()->getKey();

            try {
                $response = $index->saveObjects($objects);
                if (method_exists($response, 'wait')) {
                    $response->wait();
                }
                $processed += count($objects);
                $this->line(sprintf(
                    '[OK] Lote IDs %s–%s: %d objeto(s) guardados.',
                    (string) $firstId,
                    (string) $lastId,
                    count($objects)
                ));
            } catch (Throwable $e) {
                $errors++;
                $msg = $e->getMessage();
                $errorMessages[] = "Lote {$firstId}-{$lastId}: {$msg}";
                $this->error(sprintf(
                    '[ERROR] Lote IDs %s–%s: %s',
                    (string) $firstId,
                    (string) $lastId,
                    $msg
                ));
            }
        }, 'id');

        $this->newLine();
        $this->info('Resumen:');
        $this->line('  Registros elegibles (pre-scan): '.number_format($eligible));
        $this->line('  Objetos escritos con éxito (aprox.): '.number_format($processed));
        $this->line('  Lotes con error: '.$errors);

        if ($errorMessages !== []) {
            $this->newLine();
            $this->error('Detalle de errores:');
            foreach ($errorMessages as $m) {
                $this->line('  - '.$m);
            }
        }

        $this->postValidateId85($client->initIndex($indexName));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * objectID: según --object-id-format (scout = getScoutKey; legacy = clase::id del comando clear).
     *
     * @param  \Illuminate\Support\Collection<int, LaboratoryTest>  $models
     * @return list<array<string, mixed>>
     */
    private function buildAlgoliaObjects(Collection $models): array
    {
        $out = [];

        foreach ($models as $model) {
            if (! $model instanceof LaboratoryTest) {
                continue;
            }

            $searchableData = $model->toSearchableArray();

            if ($searchableData === [] || $searchableData === null) {
                continue;
            }

            $out[] = array_merge(
                $searchableData,
                $model->scoutMetadata(),
                ['objectID' => $this->scoutObjectId($model)],
            );
        }

        return $out;
    }

    private function scoutObjectId(LaboratoryTest $model): string
    {
        if ($this->objectIdFormat === 'legacy') {
            return LaboratoryTest::class.'::'.$model->getKey();
        }

        return (string) $model->getScoutKey();
    }

    private function postValidateId85(SearchIndex $index): void
    {
        $test = LaboratoryTest::query()
            ->whereNull('deleted_at')
            ->whereKey(85)
            ->with(['laboratoryTestCategory:id,name'])
            ->first();

        if ($test === null) {
            $this->newLine();
            $this->comment('Post-validación ID 85: no hay fila activa en MySQL; omitida.');

            return;
        }

        $objectId = $this->scoutObjectId($test);

        $this->newLine();
        $this->info('Post-validación ID 85 (comprobar en Algolia vía API):');

        try {
            $res = $index->getObject($objectId);
            $hit = is_array($res) ? $res : [];

            $brand = $hit['brand'] ?? '<ausente>';
            $gda = $hit['gda_id'] ?? '<ausente>';
            $this->line('  objectID probado: '.$objectId);
            $this->line('  brand en índice: '.$brand.' (esperado: '.$test->brand->value.')');
            $this->line('  gda_id en índice: '.$gda.' (esperado: '.$test->gda_id.')');

            if ((string) $brand === $test->brand->value && (string) $gda === (string) $test->gda_id) {
                $this->info('  Coincide con MySQL para brand y gda_id.');
            } else {
                $this->warn('  Difiere: revisa documento en dashboard o repite recuperación.');
            }
        } catch (Throwable $e) {
            $altId = $this->objectIdFormat === 'legacy'
                ? (string) $test->getScoutKey()
                : LaboratoryTest::class.'::'.$test->getKey();
            $this->warn('  No se pudo leer el objeto con objectID="'.$objectId.'": '.$e->getMessage());
            $this->comment('  Sugerencia: verificar con getObject("'.$altId.'") si el índice usa el otro formato.');
        }
    }
}
