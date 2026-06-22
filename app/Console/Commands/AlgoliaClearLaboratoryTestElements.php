<?php

namespace App\Console\Commands;

use Algolia\AlgoliaSearch\SearchClient;
use Algolia\AlgoliaSearch\SearchIndex;
use App\Models\LaboratoryTest;
use Illuminate\Console\Command;

class AlgoliaClearLaboratoryTestElements extends Command
{
    protected $signature = 'algolia:clear-laboratory-test-elements
                            {--dry-run : Solo muestra lo que se actualizaría, sin enviar cambios a Algolia}
                            {--index= : Nombre del índice Algolia. Si no se manda, usar el índice Scout de LaboratoryTest}
                            {--value= : Valor que se asignará a elements (default: - - -)}
                            {--force : Ejecuta sin confirmación interactiva}
                            {--verify : Lee los objetos después de actualizar y muestra el valor actual de elements}';

    protected $description = 'Set elements directly in Algolia for specific laboratory test objectIDs without touching the database.';

    /**
     * @var list<int>
     */
    private const OBJECT_IDS = [1803, 1804, 1805, 1806];

    public function handle(): int
    {
        $appId = (string) config('scout.algolia.id');
        $secret = (string) config('scout.algolia.secret');
        $indexName = $this->resolveIndexName();
        $elementsValue = $this->resolveElementsValue();

        if ($appId === '') {
            $this->error('Missing Algolia configuration: scout.algolia.id (ALGOLIA_APP_ID) is empty.');

            return self::FAILURE;
        }

        if ($secret === '') {
            $this->error('Missing Algolia configuration: scout.algolia.secret (ALGOLIA_SECRET) is empty.');

            return self::FAILURE;
        }

        if ($indexName === '') {
            $this->error('Algolia index name is empty. Pass --index= explicitly or check SCOUT_PREFIX / LaboratoryTest::searchableAs().');

            return self::FAILURE;
        }

        $objects = $this->buildObjects(self::OBJECT_IDS, $elementsValue);

        if ($this->option('dry-run')) {
            $this->line('DRY RUN');
            $this->line("Index: {$indexName}");
            $this->line('Objects to update:');

            foreach ($objects as $object) {
                $this->line("- objectID={$object['objectID']}, elements=\"{$object['elements']}\"");
            }

            $this->line('No changes sent to Algolia.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('This will update Algolia directly without touching DB. Continue?')) {
            return self::SUCCESS;
        }

        $this->line("Updating Algolia index: {$indexName}");
        $this->line('Updated objectIDs: '.implode(', ', self::OBJECT_IDS));

        $client = SearchClient::create($appId, $secret);
        $index = $client->initIndex($indexName);

        $response = $index->partialUpdateObjects($objects, [
            'createIfNotExists' => false,
        ]);

        $taskIds = [];

        foreach ($response as $batchResponse) {
            if (isset($batchResponse['taskID'])) {
                $taskIds[] = (string) $batchResponse['taskID'];
            }
        }

        $response->wait();

        $this->line('Algolia taskID: '.($taskIds !== [] ? implode(', ', $taskIds) : 'n/a'));

        if ($this->option('verify')) {
            $this->verifyObjects($index, self::OBJECT_IDS);
        }

        $this->line('Done.');

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{objectID: string, elements: string}>
     */
    private function buildObjects(array $ids, string $elementsValue): array
    {
        return collect($ids)->map(fn (int $id) => [
            'objectID' => $this->buildScoutObjectId($id),
            'elements' => $elementsValue,
        ])->values()->all();
    }

    private function buildScoutObjectId(int $id): string
    {
        return LaboratoryTest::class.'::'.$id;
    }

    private function resolveElementsValue(): string
    {
        $value = $this->option('value');

        if (! is_string($value) || $value === '') {
            return '- - -';
        }

        return $value;
    }

    /**
     * @param  list<int>  $ids
     */
    private function verifyObjects(SearchIndex $index, array $ids): void
    {
        $objectIds = collect($ids)
            ->map(fn (int $id) => $this->buildScoutObjectId($id))
            ->values()
            ->all();

        $response = $index->getObjects($objectIds, [
            'attributesToRetrieve' => ['elements'],
        ]);

        $this->line('Verification:');

        foreach ($response['results'] ?? [] as $result) {
            $objectId = $result['objectID'] ?? 'unknown';

            if (isset($result['message'])) {
                $this->line("objectID={$objectId} elements=<not found: {$result['message']}>");

                continue;
            }

            $elements = $result['elements'] ?? '<missing>';

            if ($elements === null) {
                $elements = '<null>';
            }

            $this->line("objectID={$objectId} elements={$elements}");
        }
    }

    private function resolveIndexName(): string
    {
        $explicitIndex = $this->option('index');

        if (is_string($explicitIndex) && $explicitIndex !== '') {
            return $explicitIndex;
        }

        // searchableAs() already prepends config('scout.prefix') to the table name.
        return (new LaboratoryTest)->searchableAs();
    }
}
