<?php

namespace App\Actions\Api\V1;

use App\Enums\LaboratoryBrand;
use App\Models\AkubicaCheckoutLink;
use App\Models\Customer;
use App\Support\Api\V1\CheckoutPreparation;
use Illuminate\Support\Str;

class GenerateAkubicaCheckoutPaymentLinkAction
{
    public function __construct(
        private readonly CheckoutPreparation $checkoutPreparation,
    ) {}

    /**
     * @return array{
     *     url: string,
     *     expires_at: string,
     *     expires_in_seconds: int,
     *     brand: string,
     *     is_ready: true,
     * }|array{error: string, missing?: list<string>}
     */
    public function __invoke(
        Customer $customer,
        LaboratoryBrand $brand,
        int $expiresInMinutes,
        ?int $createdByTokenId = null,
    ): array {
        $readiness = $this->checkoutPreparation->validatePaymentLinkReadiness($customer, $brand);

        if (isset($readiness['error'])) {
            return $readiness;
        }

        /** @var \App\Models\LaboratoryCheckoutDraft $draft */
        $draft = $readiness['draft'];

        if ($draft->checkout_step !== 'confirmation' && $draft->checkout_step !== 'appointment') {
            $draft->update(['checkout_step' => 'confirmation']);
        }

        $plainToken = Str::random(64);
        $expiresAt = now()->addMinutes($expiresInMinutes);

        AkubicaCheckoutLink::query()->create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $plainToken),
            'laboratory_brand' => $brand,
            'expires_at' => $expiresAt,
            'created_by_token_id' => $createdByTokenId,
            'metadata' => [
                'expires_in_minutes' => $expiresInMinutes,
            ],
        ]);

        return [
            'url' => route('akubica.checkout.link', ['token' => $plainToken]),
            'expires_at' => $expiresAt->utc()->toIso8601String(),
            'expires_in_seconds' => $expiresInMinutes * 60,
            'brand' => $brand->value,
            'is_ready' => true,
        ];
    }
}
