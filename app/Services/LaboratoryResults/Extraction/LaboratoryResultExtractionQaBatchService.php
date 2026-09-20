<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Models\LaboratoryResultVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class LaboratoryResultExtractionQaBatchService
{
    public function __construct(
        private readonly LaboratoryResultHybridExtractionQaService $qaService,
        private readonly LaboratoryResultExtractionQaDocumentClassifier $documentClassifier,
        private readonly LaboratoryResultExtractionQaConflictReporter $conflictReporter,
        private readonly LaboratoryResultExtractionQaBatchAggregator $aggregator,
    ) {}

    public function run(LaboratoryResultExtractionQaBatchOptions $options): LaboratoryResultExtractionQaBatchResult
    {
        $this->qaService->assertAllowedEnvironment();

        if (app()->environment('production')) {
            throw new RuntimeException('QA batch cannot run in production.');
        }

        if ($options->environment !== null && app()->environment() !== $options->environment) {
            throw new RuntimeException(
                'Current environment ('.app()->environment().') does not match required environment ('.$options->environment.').'
            );
        }

        $versions = $this->selectVersions($options);
        $documents = [];

        foreach ($versions as $version) {
            $documents[] = $this->evaluateVersion($version, $options);
        }

        return $this->aggregator->aggregate($documents);
    }

    /**
     * @return list<LaboratoryResultVersion>
     */
    private function selectVersions(LaboratoryResultExtractionQaBatchOptions $options): array
    {
        $query = LaboratoryResultVersion::query()
            ->with('resultStatus')
            ->whereNotNull('storage_path')
            ->latest('id');

        if ($options->source !== null && $options->source !== '') {
            $query->where('source', $options->source);
        }

        if ($options->status !== null && $options->status !== '') {
            $query->whereHas('resultStatus', fn ($builder) => $builder->where('status', $options->status));
        }

        return $query
            ->limit(max(1, $options->limit))
            ->get()
            ->filter(fn (LaboratoryResultVersion $version): bool => Storage::exists($version->storage_path))
            ->values()
            ->all();
    }

    private function evaluateVersion(
        LaboratoryResultVersion $version,
        LaboratoryResultExtractionQaBatchOptions $options,
    ): LaboratoryResultExtractionQaBatchDocumentResult {
        try {
            $result = $this->qaService->compareVersion($version->id, $options->forceVision);
            $category = $this->documentClassifier->classify($result->comparisonReport);
            $conflicts = $this->conflictReporter->summarize(
                $version->id,
                $result->comparisonItems,
                $options->verboseConflicts,
            );

            return LaboratoryResultExtractionQaBatchDocumentResult::fromQaResult(
                $result,
                $category,
                $conflicts,
                $result->textPageCount,
                $result->comparisonItems,
            );
        } catch (Throwable $e) {
            return LaboratoryResultExtractionQaBatchDocumentResult::failed(
                $version->id,
                $e->getMessage(),
                $version->source,
            );
        }
    }
}
