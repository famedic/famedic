<?php

namespace App\Services\Api\V1\Benefits;

use App\Http\Resources\Api\V1\Benefits\BenefitSnapshotDto;
use App\Http\Responses\ApiResponse;
use App\Models\AkubicaCheckoutLink;
use App\Enums\LaboratoryBrand;
use App\Support\Api\V1\CheckoutPreparation;
use App\Services\Api\V1\Auth\ConsumerRequestScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OpaqueCartContinuity
{
    public static function enabled(): bool
    {
        return config('akubica_connectors.continuity.enabled') === true && config('akubica_connectors.continuity.certified') === true;
    }

    public static function leo(Request $request): bool
    {
        return $request->attributes->get(ConsumerRequestScope::ATTRIBUTE)?->consumerKind === 'leo';
    }

    public function response(Request $request, bool $checkout = false): JsonResponse
    {
        try {
            if (! self::enabled() || ! self::leo($request)) {
                throw new \App\Domain\Akubica\Benefits\BenefitFailure('CONSUMER_NOT_AUTHORIZED');
            }
            $snapshot = app(LaravelOpaqueBenefits::class)->snapshot($request);
            $data = ['audience' => 'leo', 'service_line' => 'medical_services', 'cart' => BenefitSnapshotDto::from($snapshot), 'assistant_can_pay' => false, 'can_continue_on_platform' => true];
            if ($checkout) {
                $data += ['can_continue_to_payment_platform' => false, 'availability' => 'preview_only'];
            }

            if ($checkout && self::enabled()) {
                $brand = LaboratoryBrand::tryFrom((string) $request->query('brand'));
                if ($brand === null) {
                    throw new \App\Domain\Akubica\Benefits\BenefitFailure('VALIDATION_ERROR');
                }
                $customer = $request->user()->customer;
                $readiness = app(CheckoutPreparation::class)->validatePaymentLinkReadiness($customer, $brand);
                if (isset($readiness['error'])) {
                    throw new \App\Domain\Akubica\Benefits\BenefitFailure((string) $readiness['error']);
                }
                $ttl = min(10, max(1, (int) config('akubica_connectors.continuity.ttl_minutes', 10)));
                $plainToken = bin2hex(random_bytes(32));
                $expiresAt = now()->addMinutes($ttl);
                $url = route('akubica.checkout.continue', ['token' => $plainToken]);
                $allowedHost = config('akubica_connectors.continuity.allowed_host') ?: parse_url((string) config('app.url'), PHP_URL_HOST);
                if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== $allowedHost) {
                    throw new \App\Domain\Akubica\Benefits\BenefitFailure('CONTINUITY_HOST_NOT_ALLOWED');
                }
                AkubicaCheckoutLink::query()->create([
                    'customer_id' => $customer->id,
                    'token_hash' => hash('sha256', $plainToken),
                    'laboratory_brand' => $brand,
                    'expires_at' => $expiresAt,
                    'created_by_token_id' => $request->user()->currentAccessToken()?->getKey(),
                    'metadata' => ['purpose' => 'akubica_continuity', 'ttl_minutes' => $ttl],
                ]);
                $data += ['continuity_url' => $url, 'continuity_expires_at' => $expiresAt->utc()->toIso8601String()];
            }

            return ApiResponse::success($data)->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (\Throwable $e) {
            return BenefitErrorResponse::make($e);
        }
    }
}
