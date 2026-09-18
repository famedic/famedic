<?php

namespace App\Console\Commands;

use App\Models\PostalCodeLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

class ImportPostalCodeLocationsCommand extends Command
{
    private const REQUIRED_COLUMNS = ['postal_code', 'latitude', 'longitude', 'source'];

    private const OPTIONAL_COLUMNS = ['state', 'municipality', 'city', 'confidence'];

    private const CHUNK_SIZE = 1000;

    protected $signature = 'laboratory:import-postal-code-locations {path : CSV file path with postal_code, latitude, longitude, source and optional state, municipality, city, confidence} {--dry-run : Validate and report without writing rows}';

    protected $description = 'Import Mexican postal code latitude/longitude locations from a local CSV dataset.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Dataset file is not readable: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Could not open dataset file: {$path}");

            return self::FAILURE;
        }

        $headers = $this->readHeaders($handle);
        if ($headers === []) {
            fclose($handle);
            $this->error('Dataset is empty.');

            return self::FAILURE;
        }

        $headerValidation = $this->validateHeaders($headers);
        foreach ($headerValidation['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($headerValidation['missing'] !== []) {
            fclose($handle);
            $this->error('Missing required columns: '.implode(', ', $headerValidation['missing']));

            return self::FAILURE;
        }

        $stats = $this->emptyStats();
        $seenPostalCodes = [];
        $validRows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $stats['total_rows']++;

            if ($this->isEmptyRow($row)) {
                $stats['empty_rows']++;

                continue;
            }

            $payload = $this->payload($headers, $row);
            $payload['postal_code'] = $this->normalizePostalCode($payload['postal_code'] ?? null);
            $payload = $this->normalizePayload($payload);
            $validator = $this->validator($payload);

            if ($validator->fails()) {
                $stats['invalid_rows']++;
                $this->recordValidationFailures($stats, $validator);
                $this->warn("Row {$stats['total_rows']} skipped: ".$validator->errors()->first());

                continue;
            }

            if (isset($seenPostalCodes[$payload['postal_code']])) {
                $stats['duplicates']++;
                $stats['invalid_rows']++;
                $this->warn("Row {$stats['total_rows']} skipped: duplicate postal_code {$payload['postal_code']} already appeared on row {$seenPostalCodes[$payload['postal_code']]}.");

                continue;
            }

            $seenPostalCodes[$payload['postal_code']] = $stats['total_rows'];
            $validRows[] = $payload;
            $stats['valid_rows']++;
        }

        fclose($handle);

        if ($stats['total_rows'] === 0) {
            $this->error('Dataset contains headers but no data rows.');
            $this->printSummary($stats, $dryRun);

            return self::FAILURE;
        }

        $this->countInsertAndUpdateIntent($stats, $validRows);

        if (! $dryRun && $validRows !== []) {
            foreach (array_chunk($validRows, self::CHUNK_SIZE) as $chunk) {
                DB::transaction(function () use ($chunk): void {
                    $now = now();

                    PostalCodeLocation::query()->upsert(
                        array_map(fn (array $row) => [
                            ...$row,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ], $chunk),
                        ['postal_code'],
                        ['state', 'municipality', 'city', 'latitude', 'longitude', 'source', 'confidence', 'updated_at'],
                    );
                });
            }
        }

        $this->printSummary($stats, $dryRun);

        return $stats['invalid_rows'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function readHeaders(mixed $handle): array
    {
        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            return [];
        }

        return array_map(function (mixed $header): string {
            $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header) ?? (string) $header;

            return trim(strtolower($header));
        }, $headers);
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{missing: array<int, string>, warnings: array<int, string>}
     */
    private function validateHeaders(array $headers): array
    {
        $allowed = [...self::REQUIRED_COLUMNS, ...self::OPTIONAL_COLUMNS];
        $unexpected = array_values(array_diff($headers, $allowed));

        return [
            'missing' => array_values(array_diff(self::REQUIRED_COLUMNS, $headers)),
            'warnings' => $unexpected === []
                ? []
                : ['Unexpected columns will be ignored: '.implode(', ', $unexpected)],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return [
            'total_rows' => 0,
            'empty_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'duplicates' => 0,
            'invalid_postal_code' => 0,
            'invalid_latitude' => 0,
            'invalid_longitude' => 0,
            'missing_required_fields' => 0,
            'invalid_confidence' => 0,
            'would_insert' => 0,
            'would_update' => 0,
        ];
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        return collect($row)->every(fn ($value) => trim((string) $value) === '');
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string|null>  $row
     * @return array<string, string|null>
     */
    private function payload(array $headers, array $row): array
    {
        $payload = [];
        $allowed = [...self::REQUIRED_COLUMNS, ...self::OPTIONAL_COLUMNS];

        foreach ($headers as $index => $header) {
            if (! in_array($header, $allowed, true)) {
                continue;
            }

            $payload[$header] = isset($row[$index]) ? trim((string) $row[$index]) : null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        foreach (['state', 'municipality', 'city', 'source', 'confidence'] as $field) {
            $payload[$field] = isset($payload[$field]) && trim((string) $payload[$field]) !== ''
                ? trim((string) $payload[$field])
                : null;
        }

        foreach (['latitude', 'longitude'] as $field) {
            $payload[$field] = isset($payload[$field]) && trim((string) $payload[$field]) !== ''
                ? trim((string) $payload[$field])
                : null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validator(array $payload): ValidationValidator
    {
        return Validator::make($payload, [
            'postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            'state' => ['nullable', 'string', 'max:120'],
            'municipality' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:160'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'source' => ['required', 'string', 'max:64'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
        ]);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function recordValidationFailures(array &$stats, ValidationValidator $validator): void
    {
        $errors = $validator->errors();

        if ($errors->has('postal_code')) {
            $stats['invalid_postal_code']++;
        }

        if ($errors->has('latitude')) {
            $this->isMissingRequiredError($errors->first('latitude'))
                ? $stats['missing_required_fields']++
                : $stats['invalid_latitude']++;
        }

        if ($errors->has('longitude')) {
            $this->isMissingRequiredError($errors->first('longitude'))
                ? $stats['missing_required_fields']++
                : $stats['invalid_longitude']++;
        }

        if ($errors->has('source')) {
            $stats['missing_required_fields']++;
        }

        if ($errors->has('confidence')) {
            $stats['invalid_confidence']++;
        }
    }

    private function isMissingRequiredError(?string $message): bool
    {
        return str_contains((string) $message, 'required');
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array<int, array<string, mixed>>  $validRows
     */
    private function countInsertAndUpdateIntent(array &$stats, array $validRows): void
    {
        if ($validRows === []) {
            return;
        }

        $postalCodes = collect($validRows)->pluck('postal_code')->all();
        $existing = PostalCodeLocation::query()
            ->whereIn('postal_code', $postalCodes)
            ->pluck('postal_code')
            ->all();
        $existing = array_flip($existing);

        foreach ($postalCodes as $postalCode) {
            isset($existing[$postalCode])
                ? $stats['would_update']++
                : $stats['would_insert']++;
        }
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function printSummary(array $stats, bool $dryRun): void
    {
        $this->info($dryRun ? 'Dry run complete.' : 'Import complete.');
        $this->line("Total rows: {$stats['total_rows']}");
        $this->line("Valid rows: {$stats['valid_rows']}");
        $this->line("Invalid rows: {$stats['invalid_rows']}");
        $this->line("Empty rows: {$stats['empty_rows']}");
        $this->line("Duplicates: {$stats['duplicates']}");
        $this->line("Invalid CP: {$stats['invalid_postal_code']}");
        $this->line("Invalid latitude: {$stats['invalid_latitude']}");
        $this->line("Invalid longitude: {$stats['invalid_longitude']}");
        $this->line("Missing required fields: {$stats['missing_required_fields']}");
        $this->line("Invalid confidence: {$stats['invalid_confidence']}");
        $this->line(($dryRun ? 'Would insert' : 'Inserted new').": {$stats['would_insert']}");
        $this->line(($dryRun ? 'Would update' : 'Updated existing').": {$stats['would_update']}");
    }

    private function normalizePostalCode(mixed $value): ?string
    {
        $postalCode = trim((string) $value);

        return preg_match('/^\d{5}$/', $postalCode) ? $postalCode : null;
    }
}
