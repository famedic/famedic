<?php

declare(strict_types=1);

$email = $argv[1] ?? '';
$fieldId = (int) ($argv[2] ?? 0);

if ($email === '' || $fieldId <= 0) {
    fwrite(STDERR, "Usage: php scripts/ac-read-contact-field.php <email> <field_id>\n");
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ac = app(App\Services\ActiveCampaign\ActiveCampaignService::class);
$contactResult = $ac->getContactIdByEmailPublic($email);
$contactId = $contactResult->contactId;

if (! $contactResult->success || ! $contactId) {
    fwrite(STDERR, "CONTACT_NOT_FOUND\n");
    exit(2);
}

$response = Illuminate\Support\Facades\Http::withHeaders([
    'Api-Token' => (string) config('services.activecampaign.token'),
])->get(rtrim((string) config('services.activecampaign.endpoint'), '/')."/contacts/{$contactId}/fieldValues");

if (! $response->successful()) {
    fwrite(STDERR, 'API_ERROR '.$response->status()."\n");
    exit(3);
}

$values = collect($response->json('fieldValues') ?? [])
    ->first(fn (array $row) => (int) ($row['field'] ?? 0) === $fieldId);

echo "contact_id={$contactId}\n";
echo 'field_value='.(string) ($values['value'] ?? '(not set)')."\n";