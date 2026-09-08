<?php

namespace App\Services\Otp\Delivery;

use App\Models\OtpDeliveryOperation;
use App\Models\OtpSmsDeliveryReceipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vonage\Client\Signature;

/**
 * Validates inbound Vonage SMS API delivery receipts (DLR).
 *
 * Uses Vonage\Client\Signature (client-core 4.x) — official algorithm:
 * ksort → sanitize values → &key=value… → append secret (md5hash) or HMAC.
 *
 * Enable in Vonage Dashboard → API Settings → Signed webhooks (contact support if needed).
 * Set Signature method to match VONAGE_SIGNATURE_METHOD (default md5hash).
 */
final class VonageSmsDeliveryReceiptValidator
{
    /** @var list<string> */
    private const ALLOWED_SIGNATURE_METHODS = ['md5hash', 'md5', 'sha1', 'sha256', 'sha512'];

    public function enabled(): bool
    {
        return (bool) config('vonage.sms_dlr.enabled', false);
    }

    public function validateRouteToken(?string $token): bool
    {
        $expected = trim((string) config('vonage.sms_dlr.webhook_token'));

        if ($expected === '') {
            return false;
        }

        if (strlen($expected) < 32) {
            return false;
        }

        return hash_equals($expected, (string) $token);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function validateSignature(array $params): bool
    {
        $secret = trim((string) config('vonage.sms_dlr.signature_secret'));
        if ($secret === '') {
            return true;
        }

        $provided = $params['sig'] ?? null;
        if (! is_string($provided) || $provided === '') {
            return false;
        }

        $method = $this->signatureMethod();

        try {
            $signature = new Signature($params, $secret, $method);
        } catch (\Throwable) {
            return false;
        }

        return hash_equals(strtolower($provided), strtolower($signature->getSignature()));
    }

    /**
     * @return array<string, scalar|null>
     */
    public function extractParams(\Illuminate\Http\Request $request): array
    {
        $params = array_merge($request->query(), $request->post());

        return array_filter(
            $params,
            static fn ($value) => is_scalar($value) || $value === null,
        );
    }

    public function signatureMethod(): string
    {
        $method = strtolower(trim((string) config('vonage.sms_dlr.signature_method', 'md5hash')));

        return in_array($method, self::ALLOWED_SIGNATURE_METHODS, true) ? $method : 'md5hash';
    }
}
