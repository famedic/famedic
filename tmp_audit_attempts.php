<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Enums\PaymentAuthenticationAttemptEventType;

function safeEventMeta(PaymentAuthenticationAttemptEvent $event): array
{
    $m = $event->allowlistedMetadata();

    return array_intersect_key($m, array_flip([
        'response_received', 'http_status', 'failure_stage', 'exception_category',
        'normalized_reason', 'processor_transaction_id', 'duration_ms', 'pan_length',
        'track2_length', 'track2_present', 'track2_type', 'separator_kind',
        'expiration_format', 'payload_schema_version', 'token_usuario_present',
        'provider_code_string', 'amount', 'currency', 'request_dispatched',
    ]));
}

function summarizeAttempt(PaymentAuthenticationAttempt $a): void
{
    $session = $a->efevoo3dsSession;
    echo '--- attempt id='.$a->id.' ref='.$a->support_reference.PHP_EOL;
    echo 'order='.($a->provider_order_id ?? '-').' session='.($a->efevoo_3ds_session_id ?? '-').PHP_EOL;
    echo 'status='.$a->status.' cat='.($a->failure_category ?? '-').' code='.($a->provider_code ?? '-').PHP_EOL;
    echo 'polls='.$a->status_poll_call_count.' token_calls='.$a->tokenization_call_count.PHP_EOL;
    if ($session) {
        echo 'session_status='.$session->status.PHP_EOL;
    }
    foreach ([
        PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value,
        PaymentAuthenticationAttemptEventType::TokenizationRequestStarted->value,
        PaymentAuthenticationAttemptEventType::TokenizationRequestFailed->value,
        PaymentAuthenticationAttemptEventType::TokenizationFailed->value,
    ] as $type) {
        $ev = $a->events()->where('event_type', $type)->first();
        if ($ev) {
            echo 'event '.$type.' http='.($ev->http_status ?? '-').' code='.($ev->provider_code ?? '-').PHP_EOL;
            $meta = safeEventMeta($ev);
            if ($meta !== []) {
                echo '  meta='.json_encode($meta).PHP_EOL;
            }
        }
    }
}

$banorte = PaymentAuthenticationAttempt::where('support_reference', 'AUTH-20260826135500-KDKFSVFB')->first();
if ($banorte) {
    summarizeAttempt($banorte);
}

echo PHP_EOL.'Recent tokenization_failed attempts:'.PHP_EOL;
PaymentAuthenticationAttempt::query()
    ->where('tokenization_call_count', '>', 0)
    ->where(function ($q) {
        $q->where('failure_category', 'tokenization_failed')
            ->orWhereHas('efevoo3dsSession', fn ($s) => $s->where('status', 'tokenization_failed'));
    })
    ->orderByDesc('id')
    ->limit(8)
    ->get()
    ->each(fn ($a) => summarizeAttempt($a));

echo PHP_EOL.'Completed tokenizations (recent):'.PHP_EOL;
PaymentAuthenticationAttempt::query()
    ->where('status', 'completed')
    ->where('tokenization_call_count', '>', 0)
    ->orderByDesc('id')
    ->limit(5)
    ->get()
    ->each(fn ($a) => summarizeAttempt($a));

echo PHP_EOL.'Tokens 569/570:'.PHP_EOL;
foreach ([569, 570] as $id) {
    $t = EfevooToken::find($id);
    if (! $t) {
        echo 'token '.$id.' not found'.PHP_EOL;
        continue;
    }
    $sessionId = is_array($t->metadata) ? ($t->metadata['session_id'] ?? null) : null;
    echo 'token '.$id.' env='.$t->environment.' gateway='.($t->metadata['gateway_origin'] ?? '-').' session='.($sessionId ?? '-').PHP_EOL;
}
