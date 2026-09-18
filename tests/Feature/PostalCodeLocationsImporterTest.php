<?php

use App\Models\PostalCodeLocation;

it('dry-runs a valid csv without modifying the database', function (): void {
    $path = postalCodeCsv([
        ['postal_code', 'latitude', 'longitude', 'source', 'state', 'municipality', 'city', 'confidence'],
        ['06400', '19.432100', '-99.133200', 'official', 'Ciudad de Mexico', 'Cuauhtemoc', 'Ciudad de Mexico', '0.9'],
    ]);

    $this->artisan('laboratory:import-postal-code-locations', ['path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Dry run complete.')
        ->expectsOutputToContain('Valid rows: 1')
        ->expectsOutputToContain('Would insert: 1')
        ->assertSuccessful();

    expect(PostalCodeLocation::query()->count())->toBe(0);
});

it('imports valid rows and preserves leading zero postal codes', function (): void {
    $path = postalCodeCsv([
        ['postal_code', 'latitude', 'longitude', 'source', 'state', 'municipality', 'city', 'confidence'],
        ['06400', '19.432100', '-99.133200', 'official', 'Ciudad de Mexico', 'Cuauhtemoc', 'Ciudad de Mexico', '0.9'],
    ]);

    $this->artisan('laboratory:import-postal-code-locations', ['path' => $path])
        ->expectsOutputToContain('Import complete.')
        ->expectsOutputToContain('Inserted new: 1')
        ->assertSuccessful();

    $this->assertDatabaseHas('postal_code_locations', [
        'postal_code' => '06400',
        'source' => 'official',
    ]);
});

it('is idempotent and updates existing postal code locations', function (): void {
    $first = postalCodeCsv([
        ['postal_code', 'latitude', 'longitude', 'source'],
        ['64000', '25.670000', '-100.310000', 'official'],
    ]);
    $second = postalCodeCsv([
        ['postal_code', 'latitude', 'longitude', 'source', 'municipality'],
        ['64000', '25.680000', '-100.320000', 'official', 'Monterrey'],
    ]);

    $this->artisan('laboratory:import-postal-code-locations', ['path' => $first])
        ->assertSuccessful();
    $this->artisan('laboratory:import-postal-code-locations', ['path' => $second])
        ->expectsOutputToContain('Updated existing: 1')
        ->assertSuccessful();

    expect(PostalCodeLocation::query()->count())->toBe(1);
    $this->assertDatabaseHas('postal_code_locations', [
        'postal_code' => '64000',
        'municipality' => 'Monterrey',
    ]);
});

it('rejects invalid postal codes coordinates confidence and duplicate rows', function (): void {
    $path = postalCodeCsv([
        ['postal_code', 'latitude', 'longitude', 'source', 'confidence'],
        ['6400', '25.670000', '-100.310000', 'official', '0.9'],
        ['64000', 'not-lat', '-100.310000', 'official', '0.9'],
        ['64001', '25.670000', 'not-lng', 'official', '0.9'],
        ['64002', '25.670000', '-100.310000', 'official', '2'],
        ['64003', '25.670000', '-100.310000', 'official', '0.8'],
        ['64003', '25.680000', '-100.320000', 'official', '0.8'],
    ]);

    $this->artisan('laboratory:import-postal-code-locations', ['path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Invalid rows: 5')
        ->expectsOutputToContain('Duplicates: 1')
        ->expectsOutputToContain('Invalid CP: 1')
        ->expectsOutputToContain('Invalid latitude: 1')
        ->expectsOutputToContain('Invalid longitude: 1')
        ->expectsOutputToContain('Invalid confidence: 1')
        ->assertFailed();
});

it('rejects missing required columns', function (): void {
    $path = postalCodeCsv([
        ['postal_code', 'latitude', 'source'],
        ['64000', '25.670000', 'official'],
    ]);

    $this->artisan('laboratory:import-postal-code-locations', ['path' => $path])
        ->expectsOutputToContain('Missing required columns: longitude')
        ->assertFailed();
});

it('handles empty rows unexpected columns and utf8 bom headers', function (): void {
    $path = postalCodeCsv([
        ["\xEF\xBB\xBFpostal_code", 'latitude', 'longitude', 'source', 'unexpected'],
        ['', '', '', '', ''],
        ['64000', '25.670000', '-100.310000', 'official', 'ignored'],
    ]);

    $this->artisan('laboratory:import-postal-code-locations', ['path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Unexpected columns will be ignored: unexpected')
        ->expectsOutputToContain('Empty rows: 1')
        ->expectsOutputToContain('Valid rows: 1')
        ->assertSuccessful();
});

it('rejects missing files and datasets without rows', function (): void {
    $this->artisan('laboratory:import-postal-code-locations', ['path' => storage_path('app/missing-postal-codes.csv')])
        ->expectsOutputToContain('Dataset file is not readable')
        ->assertFailed();

    $path = postalCodeCsv([
        ['postal_code', 'latitude', 'longitude', 'source'],
    ]);

    $this->artisan('laboratory:import-postal-code-locations', ['path' => $path])
        ->expectsOutputToContain('Dataset contains headers but no data rows.')
        ->assertFailed();
});

function postalCodeCsv(array $rows): string
{
    $path = storage_path('framework/testing/postal-code-locations-'.bin2hex(random_bytes(6)).'.csv');
    $handle = fopen($path, 'w');

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);

    return $path;
}
