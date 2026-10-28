<?php

namespace App\Support;

use App\Models\LaboratoryPurchase;
use RuntimeException;

final class LocalCheckoutQa
{
    public static function enabled(): bool
    {
        return app()->environment('local') && filter_var(config('local-checkout-qa.enabled'), FILTER_VALIDATE_BOOL);
    }

    public static function recipient(): string
    {
        $email = strtolower(trim((string) config('local-checkout-qa.recipient')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('local_qa_recipient_required');
        }
        return $email;
    }

    public static function owns(LaboratoryPurchase $purchase): bool
    {
        return strtolower((string) $purchase->customer?->user?->email) === self::recipient();
    }

    public static function assertRecipients(array $addresses): void
    {
        $allowed = self::recipient();
        if ($addresses === []) {
            throw new RuntimeException('local_qa_empty_envelope');
        }
        foreach ($addresses as $address) {
            if (strtolower(trim($address->getAddress())) !== $allowed) {
                // Never include addresses, subject or message content in diagnostics.
                throw new RuntimeException('local_qa_recipient_blocked');
            }
        }
    }

    public static function fingerprint(): string
    {
        return substr(hash('sha256', json_encode([
            self::enabled(), config('local-checkout-qa'), config('laboratory-confirmation'),
            config('queue.connections.database.retry_after'), config('app.env'),
            config('services.laboratory_preparation.deterministic_v3_enabled'),
            config('services.laboratory_preparation.deterministic_v3_shadow_enabled'),
            config('mail.default'), collect(config('mail.mailers.'.config('mail.default'), []))->only(['transport', 'host', 'port', 'scheme'])->all(),
        ])), 0, 20);
    }
}
