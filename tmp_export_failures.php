<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\EfevooToken;
use App\Models\PaymentAttempt;
use App\Support\MockEfevooPaymentSupport;

$days = 14;
$since = now()->subDays($days);

$attempts = PaymentAttempt::query()
    ->with(['customer.user'])
    ->where('created_at', '>=', $since)
    ->whereIn('status', ['declined', 'error'])
    ->orderByDesc('created_at')
    ->get();

function redactEmail(?string $email): string
{
    if (! $email || ! str_contains($email, '@')) {
        return '—';
    }
    [$local, $domain] = explode('@', $email, 2);

    return substr($local, 0, 1) . '***@' . $domain;
}

function sanitizeRaw(?array $raw): array
{
    if (! is_array($raw)) {
        return [];
    }
    $json = json_encode($raw);
    // Never export anything that could look like track/PAN blobs
    if (strlen($json) > 8000) {
        $raw = ['_truncated' => true, 'preview' => json_decode(substr($json, 0, 8000), true)];
    }

    return $raw;
}

function classify(PaymentAttempt $a): string
{
    $raw = json_encode($a->raw_response ?? []);
    $msg = strtolower((string) ($a->processor_message ?? ''));
    if (str_contains($msg, 'ambiente local') || str_contains($raw, 'MOCK')) {
        return 'simulacion_local_qa';
    }
    if (str_contains($raw, 'track2') || str_contains($raw, 'Track2')) {
        return 'token_track2_invalido';
    }
    if (str_contains($raw, 'Llaves incorrectas') || str_contains($msg, 'token inválido')) {
        return 'credenciales_o_cifrado';
    }
    if ($a->status === 'declined' && filled($a->processor_code) && (string) $a->processor_code !== '00') {
        return 'rechazo_emisor_bancario';
    }

    return 'otro';
}

$byClass = [];
foreach ($attempts as $a) {
    $byClass[classify($a)][] = $a;
}

$out = [
    'generated_at' => now()->toIso8601String(),
    'period_days' => $days,
    'merchant_cliente' => config('efevoopay.cliente'),
    'api_url' => config('efevoopay.api_url'),
    'environment' => config('efevoopay.environment'),
    'totals' => [
        'failed_attempts' => $attempts->count(),
        'declined' => $attempts->where('status', 'declined')->count(),
        'error' => $attempts->where('status', 'error')->count(),
    ],
    'summary_by_category' => collect($byClass)->map(fn ($list) => count($list))->all(),
    'cases' => [],
];

foreach ($attempts as $a) {
    $token = EfevooToken::withTrashed()->find($a->token_id);
    $email = $a->customer?->user?->email;
    $out['cases'][] = [
        'payment_attempt_id' => $a->id,
        'category' => classify($a),
        'status' => $a->status,
        'created_at_utc' => $a->created_at?->toIso8601String(),
        'processed_at_utc' => $a->processed_at?->toIso8601String(),
        'amount_mxn' => round(($a->amount_cents ?? 0) / 100, 2),
        'reference' => $a->reference,
        'gateway' => $a->gateway,
        'processor_code' => $a->processor_code,
        'processor_message' => $a->processor_message,
        'processor_transaction_id' => $a->processor_transaction_id,
        'customer_id' => $a->customer_id,
        'contact_email_redacted' => redactEmail($email),
        'efevoo_token_id' => $a->token_id,
        'card_last_four' => $token?->card_last_four,
        'token_environment' => $token?->environment,
        'token_created_at' => $token?->created_at?->toIso8601String(),
        'token_is_mock' => $token ? MockEfevooPaymentSupport::isMockToken($token) : null,
        'prior_approved_count_same_token' => $a->token_id
            ? PaymentAttempt::query()->where('token_id', $a->token_id)->where('status', 'approved')->where('id', '<', $a->id)->count()
            : 0,
        'processor_payload' => sanitizeRaw($a->raw_response),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
