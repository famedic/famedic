<?php

namespace App\Services\Benavides;

use App\Models\BenavidesCode;
use App\Models\BenavidesCodeImport;
use App\Models\User;
use Illuminate\Support\Carbon;

class BenavidesCodeImportService
{
    public function __construct(
        private BenavidesCodeFileParser $parser,
        private BenavidesCodeImportPreviewService $previewService,
    ) {}

    public function confirm(int $importId, User $admin): array
    {
        $run = BenavidesCodeImport::query()->findOrFail($importId);

        if ($run->status === BenavidesCodeImport::STATUS_COMPLETED) {
            return $this->result($run, $run->summary_json ?? []);
        }

        if ($run->status !== BenavidesCodeImport::STATUS_PREVIEWED) {
            abort(422, 'La importación no está lista para confirmarse.');
        }

        $run->update(['status' => BenavidesCodeImport::STATUS_IMPORTING]);

        try {
            $rows = $this->parser->parseStoragePath((string) $run->stored_path);
            $analysis = $this->previewService->analyze($rows);
            $uniqueCodes = $this->importableCodes($rows);
            $now = Carbon::now();
            $importedRows = 0;

            foreach (array_chunk($uniqueCodes, 1000) as $chunk) {
                $existing = BenavidesCode::query()
                    ->whereIn('code', $chunk)
                    ->pluck('code')
                    ->all();
                $existingLookup = array_flip($existing);
                $insertRows = [];

                foreach ($chunk as $code) {
                    if (isset($existingLookup[$code])) {
                        continue;
                    }

                    $insertRows[] = [
                        'code' => $code,
                        'import_batch_id' => $run->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($insertRows !== []) {
                    BenavidesCode::query()->insertOrIgnore($insertRows);
                    $importedRows += BenavidesCode::query()
                        ->where('import_batch_id', $run->id)
                        ->whereIn('code', array_column($insertRows, 'code'))
                        ->count();
                }
            }

            $run->update([
                'status' => BenavidesCodeImport::STATUS_COMPLETED,
                'confirmed_by_user_id' => $admin->id,
                'confirmed_at' => now(),
                'total_rows' => $analysis['total_rows'],
                'valid_rows' => $analysis['valid_rows'],
                'empty_rows' => $analysis['empty_rows'],
                'duplicate_file_rows' => $analysis['duplicate_file_rows'],
                'duplicate_database_rows' => $analysis['duplicate_database_rows'],
                'assigned_conflict_rows' => $analysis['assigned_conflict_rows'],
                'imported_rows' => $importedRows,
                'rejected_rows' => max(0, $analysis['total_rows'] - $importedRows),
                'summary_json' => [...$analysis, 'imported_rows' => $importedRows],
            ]);

            return $this->result($run->fresh(['confirmedByUser']), $analysis);
        } catch (\Throwable $e) {
            $run->update([
                'status' => BenavidesCodeImport::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  list<string|null>  $rows
     * @return list<string>
     */
    private function importableCodes(array $rows): array
    {
        $codes = [];
        $seen = [];

        foreach ($rows as $code) {
            if (! $code || isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;
            $codes[] = $code;
        }

        return $codes;
    }

    private function result(BenavidesCodeImport $run, array $analysis): array
    {
        return [
            'import_id' => $run->id,
            'status' => $run->status,
            'imported_rows' => $run->imported_rows,
            'rejected_rows' => $run->rejected_rows,
            'duplicate_database_rows' => $run->duplicate_database_rows,
            'assigned_conflict_rows' => $run->assigned_conflict_rows,
            'summary' => $run->summary_json ?: $analysis,
        ];
    }
}
