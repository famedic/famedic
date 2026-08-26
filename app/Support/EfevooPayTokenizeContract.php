<?php

namespace App\Support;

/**
 * Contrato documentado EfevooPay getTokenize (track2 + amount cifrados).
 *
 * @see https://documenter.efevoopay.com/ — TokenCard / getTokenize
 */
class EfevooPayTokenizeContract
{
    public const PAYLOAD_SCHEMA_VERSION = 'efevoo-documented-v1';

    public const DOCUMENTED_TRACK2_PATTERN = '/^(?<pan>\d{13,19})=(?<exp>\d{4})$/';

    public const SEPARATOR_KIND_EQUALS = 'equals';

    public const EXPIRATION_INPUT_MMYy = 'MMYY';

    public const EXPIRATION_TRACK_YYMM = 'YYMM';

    /** Claves allowlisted en metadata admin de eventos TokenCard (sin datos sensibles). */
    public const TOKENIZE_DESCRIPTOR_KEYS = [
        'payload_schema_version',
        'track2_present',
        'track2_type',
        'track2_length',
        'pan_length',
        'expiration_format',
        'separator_kind',
        'amount',
        'currency',
        'request_dispatched',
        'response_received',
        'http_status',
        'provider_code_type',
        'provider_code_string',
        'token_usuario_present',
        'duration_ms',
        'normalized_reason',
        'local_validation_passed',
        'local_validation_reason',
    ];

    /** Claves prohibidas en metadata/export de TokenCard. */
    public const TOKENIZE_FORBIDDEN_METADATA_KEYS = [
        'pan', 'bin', 'last4', 'card_last4', 'cvv', 'holder', 'card_holder', 'alias',
        'track', 'track2', 'client_token', 'token_usuario', 'payload', 'encrypt',
        'authorization', 'headers', 'raw_response', 'card_number', 'card_token',
    ];

    /**
     * Construye track2 documentado: PAN=YYMM (sin service code en el ejemplo oficial).
     */
    public static function buildTrack2(string $panDigits, string $expirationMmyy): string
    {
        $panDigits = preg_replace('/\D/', '', $panDigits) ?? '';
        $expirationMmyy = preg_replace('/\D/', '', $expirationMmyy) ?? '';

        if (strlen($panDigits) < 13 || strlen($panDigits) > 19) {
            throw new \InvalidArgumentException('PAN inválido para track2');
        }
        if (strlen($expirationMmyy) !== 4) {
            throw new \InvalidArgumentException('Expiración inválida para track2');
        }

        $mm = substr($expirationMmyy, 0, 2);
        $yy = substr($expirationMmyy, 2, 2);

        return $panDigits.'='.$yy.$mm;
    }

    /**
     * Valida forma documentada antes de llamar al proveedor.
     *
     * @return array{valid: bool, reason: string|null}
     */
    public static function validateTrack2Shape(string $panDigits, string $expirationMmyy): array
    {
        try {
            $track2 = self::buildTrack2($panDigits, $expirationMmyy);
        } catch (\InvalidArgumentException $e) {
            return ['valid' => false, 'reason' => 'invalid_track_data'];
        }

        if (! preg_match(self::DOCUMENTED_TRACK2_PATTERN, $track2)) {
            return ['valid' => false, 'reason' => 'invalid_track_data'];
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Descriptor allowlisted para timeline admin (sin datos sensibles).
     *
     * @param  array<string, mixed>  $cardData  Normalizado (PAN MMYY, sin CVV)
     * @return array<string, mixed>
     */
    public static function describeRequest(array $cardData, ?float $amount = null): array
    {
        $pan = preg_replace('/\D/', '', (string) ($cardData['card_number'] ?? '')) ?? '';
        $expiration = preg_replace('/\D/', '', (string) ($cardData['expiration'] ?? '')) ?? '';
        $shape = self::validateTrack2Shape($pan, $expiration);
        $track2Length = null;

        if ($shape['valid']) {
            $track2Length = strlen(self::buildTrack2($pan, $expiration));
        }

        $amountValue = $amount ?? (isset($cardData['amount']) ? (float) $cardData['amount'] : null);

        return [
            'payload_schema_version' => self::PAYLOAD_SCHEMA_VERSION,
            'track2_present' => $shape['valid'],
            'track2_type' => 'string',
            'track2_length' => $track2Length,
            'pan_length' => strlen($pan) > 0 ? strlen($pan) : null,
            'expiration_format' => self::EXPIRATION_INPUT_MMYy,
            'separator_kind' => self::SEPARATOR_KIND_EQUALS,
            'amount' => $amountValue !== null ? number_format($amountValue, 2, '.', '') : null,
            'currency' => PaymentAuthenticationEfevooPayAmounts::currency(),
            'request_dispatched' => false,
            'local_validation_passed' => $shape['valid'],
            'local_validation_reason' => $shape['reason'],
        ];
    }

    /**
     * Detecta razón normalizada a partir del mensaje crudo del proveedor (pre-sanitizer).
     */
    public static function normalizedReasonFromProviderMessage(?string $rawMessage): ?string
    {
        if (! is_string($rawMessage) || trim($rawMessage) === '') {
            return null;
        }

        $lower = strtolower($rawMessage);

        if (str_contains($lower, 'bad track')
            || str_contains($lower, 'track data')
            || str_contains($lower, 'datos de pista')) {
            return 'invalid_track_data';
        }

        return null;
    }

    /**
     * Tipo y representación del código del proveedor sin coerción numérica previa.
     *
     * @return array{provider_code_type: string|null, provider_code_string: string|null}
     */
    public static function describeProviderCode(mixed $codigo): array
    {
        if ($codigo === null || $codigo === '') {
            return ['provider_code_type' => null, 'provider_code_string' => null];
        }

        if (is_int($codigo)) {
            return [
                'provider_code_type' => 'integer',
                'provider_code_string' => (string) $codigo,
            ];
        }

        if (is_string($codigo)) {
            $trimmed = trim($codigo);

            return [
                'provider_code_type' => $trimmed === '' ? null : 'string',
                'provider_code_string' => $trimmed === '' ? null : mb_substr($trimmed, 0, 16),
            ];
        }

        if (is_scalar($codigo)) {
            $string = trim((string) $codigo);

            return [
                'provider_code_type' => 'other',
                'provider_code_string' => $string === '' ? null : mb_substr($string, 0, 16),
            ];
        }

        return ['provider_code_type' => 'other', 'provider_code_string' => null];
    }

    /**
     * Preserva el código tal como lo envió el proveedor, sin coerción numérica.
     */
    public static function preserveProviderCodeString(mixed $codigo): ?string
    {
        return self::describeProviderCode($codigo)['provider_code_string'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function tokenUsuarioPresent(array $data): bool
    {
        $token = $data['token_usuario'] ?? null;

        return is_string($token) && trim($token) !== '';
    }
}
