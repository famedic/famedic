<?php

namespace App\Services\Benavides;

use App\Models\BenavidesCode;
use App\Models\BenavidesCodeImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class BenavidesCodeImportPreviewService
{
    public function __construct(private BenavidesCodeFileParser $parser) {}

    public function preview(UploadedFile $file, User $admin): array
    {
        $this->parser->validateUpload($file);

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $storedPath = $file->storeAs('imports/benavides', uniqid('benavides_', true).'.'.$extension, 'local');
        $hash = hash_file('sha256', Storage::disk('local')->path($storedPath));
        $rows = $this->parser->parseStoragePath($storedPath);
        $analysis = $this->analyze($rows);

        $run = BenavidesCodeImport::query()->create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_hash' => $hash,
            'status' => BenavidesCodeImport::STATUS_PREVIEWED,
            'uploaded_by_user_id' => $admin->id,
            ...$this->countersForRun($analysis, 0),
            'summary_json' => $analysis,
        ]);

        return $this->present($run, $analysis);
    }

    /**
     * @param  list<string|null>  $rows
     */
    public function analyze(array $rows): array
    {
        $totalRows = count($rows);
        $emptyExamples = [];
        $duplicateFileExamples = [];
        $validCodes = [];
        $seen = [];
        $duplicateFileRows = 0;
        $emptyRows = 0;

        foreach ($rows as $index => $code) {
            $rowNumber = $index + 1;
            if ($code === null || $code === '') {
                $emptyRows++;
                $this->pushExample($emptyExamples, ['row' => $rowNumber]);
                continue;
            }

            if (isset($seen[$code])) {
                $duplicateFileRows++;
                $this->pushExample($duplicateFileExamples, ['row' => $rowNumber, 'code' => $code]);
                continue;
            }

            $seen[$code] = true;
            $validCodes[] = $code;
        }

        $existing = BenavidesCode::query()
            ->whereIn('code', $validCodes)
            ->get(['code', 'user_id'])
            ->keyBy('code');

        $databaseDuplicateExamples = [];
        $assignedConflictExamples = [];
        $duplicateDatabaseRows = 0;
        $assignedConflictRows = 0;
        $importableRows = 0;

        foreach ($validCodes as $code) {
            $existingCode = $existing->get($code);
            if (! $existingCode) {
                $importableRows++;
                continue;
            }

            $duplicateDatabaseRows++;
            $this->pushExample($databaseDuplicateExamples, ['code' => $code]);

            if ($existingCode->user_id !== null) {
                $assignedConflictRows++;
                $this->pushExample($assignedConflictExamples, ['code' => $code, 'user_id' => $existingCode->user_id]);
            }
        }

        return [
            'total_rows' => $totalRows,
            'valid_rows' => count($validCodes),
            'empty_rows' => $emptyRows,
            'duplicate_file_rows' => $duplicateFileRows,
            'duplicate_database_rows' => $duplicateDatabaseRows,
            'assigned_conflict_rows' => $assignedConflictRows,
            'importable_rows' => $importableRows,
            'examples' => [
                'empty' => $emptyExamples,
                'duplicate_file' => $duplicateFileExamples,
                'duplicate_database' => $databaseDuplicateExamples,
                'assigned_conflict' => $assignedConflictExamples,
            ],
        ];
    }

    public function present(BenavidesCodeImport $run, array $analysis): array
    {
        return [
            'import_id' => $run->id,
            'filename' => $run->original_filename,
            'status' => $run->status,
            'summary' => $analysis,
            'format_note' => 'Formato recomendado: una columna code. Los códigos se tratan como texto; si Excel ya eliminó ceros iniciales antes de subir el archivo, FAMEDIC mostrará el valor recibido sin inventar ceros.',
        ];
    }

    private function countersForRun(array $analysis, int $importedRows): array
    {
        return [
            'total_rows' => $analysis['total_rows'],
            'valid_rows' => $analysis['valid_rows'],
            'empty_rows' => $analysis['empty_rows'],
            'duplicate_file_rows' => $analysis['duplicate_file_rows'],
            'duplicate_database_rows' => $analysis['duplicate_database_rows'],
            'assigned_conflict_rows' => $analysis['assigned_conflict_rows'],
            'imported_rows' => $importedRows,
            'rejected_rows' => $analysis['total_rows'] - $importedRows,
        ];
    }

    private function pushExample(array &$examples, array $example): void
    {
        if (count($examples) < 20) {
            $examples[] = $example;
        }
    }
}
