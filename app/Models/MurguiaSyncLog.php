<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MurguiaSyncLog extends Model
{
    public const ACTION_ALTA = 'alta';

    public const ACTION_BAJA = 'baja';

    public const ACTION_VALIDACION = 'validacion';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NOT_FOUND = 'not_found';

    public const ENTRY_TYPE_BULK = 'bulk';

    public const ENTRY_TYPE_SINGLE = 'single';

    protected $fillable = [
        'customer_id',
        'triggered_by',
        'email',
        'medical_attention_identifier',
        'action',
        'request_payload',
        'response_payload',
        'status',
        'message',
        'entry_type',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $log): void {
            $log->sanitizeForStorage();
        });
    }

    public function sanitizeForStorage(): void
    {
        $logSafeResponse = $this->safeResponsePayload($this->response_payload);

        $this->email = null;
        $this->medical_attention_identifier = null;
        $this->request_payload = null;
        $this->response_payload = $logSafeResponse;
        $this->message = $this->safeEventCode($this->message);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    private function safeResponsePayload(mixed $payload): ?array
    {
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        return array_filter([
            'http_status' => isset($payload['http_status']) ? (int) $payload['http_status'] : null,
            'result_code' => isset($payload['result_code']) ? $this->safeEventCode((string) $payload['result_code']) : null,
            'error_code' => isset($payload['error_code'])
                ? $this->safeEventCode((string) $payload['error_code'])
                : (isset($payload['exception_type']) ? class_basename((string) $payload['exception_type']) : null),
            'synced' => array_key_exists('synced', $payload) ? (bool) $payload['synced'] : null,
        ], fn ($value) => $value !== null);
    }

    private function safeEventCode(mixed $value): string
    {
        $redacted = preg_replace([
            '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i',
            '/\b\d{6,}\b/',
        ], 'redacted', (string) $value) ?? '';

        $code = Str::of($redacted)
            ->lower()
            ->replaceMatches('/[^a-z0-9_.-]+/', '_')
            ->trim('_')
            ->toString();

        return $code !== '' ? mb_substr($code, 0, 120) : 'murguia.event';
    }
}
