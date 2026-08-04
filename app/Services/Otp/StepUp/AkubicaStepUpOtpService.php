<?php

namespace App\Services\Otp\StepUp;

use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\OtpChallengeMismatchException;
use App\Exceptions\Otp\OtpConfigurationException;
use App\Exceptions\Otp\OtpDeliveryFailedException;
use App\Exceptions\Otp\OtpIdentityNormalizationException;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\OtpChallenge;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Services\Otp\CreateOtpChallengeData;
use App\Services\Otp\Delivery\AkubicaSecureOtpDeliveryOrchestrator;
use App\Services\Otp\Delivery\OtpDeliveryOutcome;
use App\Services\Otp\OtpAbusePolicy;
use App\Services\Otp\OtpRequestContext;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use App\Services\Otp\Registration\PhoneIdentity;
use App\Support\Api\V1\OrderDocumentDownloadSupport;
use Illuminate\Support\Str;

/**
 * P0-B1/B3 — Step-up OTP for sensitive Akubica resources (results + invoices).
 *
 * Does not gate downloads yet. Issues otp_step_up_grants after SMS verify.
 */
class AkubicaStepUpOtpService
{
    public const CONTEXT_TYPE_LABORATORY_PURCHASE = 'laboratory_purchase';

    public const CONTEXT_TYPE_INVOICE = 'invoice';

    public function __construct(
        private readonly OtpAbusePolicy $abusePolicy,
        private readonly AkubicaSecureOtpDeliveryOrchestrator $deliveryOrchestrator,
        private readonly MexicoPhoneNormalizer $phoneNormalizer,
        private readonly OtpStepUpGrantService $grantService,
        private readonly OrderDocumentDownloadSupport $orderOwnership,
    ) {
    }

    public static function isResultsEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.step_up_results_enabled', false);
    }

    public static function isInvoicesEnabled(): bool
    {
        return (bool) config('otp.p0a.flags.step_up_invoices_enabled', false);
    }

    /**
     * @throws OtpConfigurationException
     */
    public function assertResultsConfigurationReady(): void
    {
        if (! self::isResultsEnabled()) {
            throw new OtpConfigurationException(
                'El step-up OTP de resultados no esta habilitado.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        $this->assertSharedInfrastructureReady();
    }

    /**
     * @throws OtpConfigurationException
     */
    public function assertSharedInfrastructureReady(): void
    {
        if (! (bool) config('otp.p0a.flags.anti_abuse_enabled', false)) {
            throw new OtpConfigurationException(
                'step_up requiere anti_abuse_enabled.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        if (! (bool) config('otp.p0a.flags.sms_delivery_enabled', false)) {
            throw new OtpConfigurationException(
                'step_up requiere sms_delivery_enabled.',
                'OTP_CONFIGURATION_INVALID',
            );
        }
    }

    /**
     * @throws OtpConfigurationException
     */
    public function assertInvoicesConfigurationReady(): void
    {
        if (! self::isInvoicesEnabled()) {
            throw new OtpConfigurationException(
                'El step-up OTP de facturas no esta habilitado.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        $this->assertSharedInfrastructureReady();
    }

    public function findOwnedOrder(Customer $customer, int $orderId): ?LaboratoryPurchase
    {
        return $this->orderOwnership->findCustomerOrder($customer, $orderId);
    }

    public function findOwnedInvoice(LaboratoryPurchase $order, int $invoiceId): ?\App\Models\Invoice
    {
        return $this->orderOwnership->findOwnedInvoice($order, $invoiceId);
    }

    /**
     * Resolve phone from the authenticated user — never trust client phone input.
     *
     * @throws OtpIdentityNormalizationException
     * @throws OtpConfigurationException
     */
    public function resolveAuthenticatedPhone(User $user): PhoneIdentity
    {
        $raw = $this->rawPhoneFromUser($user);
        if ($raw === null || $raw === '') {
            throw new OtpConfigurationException(
                'El usuario no tiene un telefono elegible para step-up.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        $country = is_string($user->phone_country) && $user->phone_country !== ''
            ? $user->phone_country
            : MexicoPhoneNormalizer::DEFAULT_COUNTRY;

        $phone = $this->phoneNormalizer->normalize($raw, $country);

        if ((bool) config('otp.p0a.policy.require_verified_phone', true)
            && $user->phone_verified_at === null
        ) {
            throw new OtpConfigurationException(
                'El telefono del usuario no esta verificado.',
                'OTP_CONFIGURATION_INVALID',
            );
        }

        return $phone;
    }

    /**
     * Request step-up OTP for results on an owned laboratory purchase.
     *
     * @return array<string, mixed>
     *
     * @throws OtpDeliveryFailedException
     * @throws OtpConfigurationException
     * @throws OtpIdentityNormalizationException
     */
    public function requestResultsStepUp(
        User $user,
        LaboratoryPurchase $order,
        ?int $personalAccessTokenId,
        ?string $clientIp,
    ): array {
        $this->assertResultsConfigurationReady();

        $phone = $this->resolveAuthenticatedPhone($user);
        $purpose = P0aOtpPurpose::StepUpResults;
        $resourceType = OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE;
        $resourceId = (int) $order->id;

        return $this->request(
            user: $user,
            phone: $phone,
            purpose: $purpose,
            resourceType: $resourceType,
            resourceId: $resourceId,
            personalAccessTokenId: $personalAccessTokenId,
            clientIp: $clientIp,
            metaExtra: [
                'order_id' => (int) $order->id,
            ],
        );
    }

    /**
     * Request step-up OTP for an owned invoice (bound to order + invoice).
     *
     * @return array<string, mixed>
     *
     * @throws OtpDeliveryFailedException
     * @throws OtpConfigurationException
     * @throws OtpIdentityNormalizationException
     */
    public function requestInvoicesStepUp(
        User $user,
        LaboratoryPurchase $order,
        \App\Models\Invoice $invoice,
        ?int $personalAccessTokenId,
        ?string $clientIp,
    ): array {
        $this->assertInvoicesConfigurationReady();

        $phone = $this->resolveAuthenticatedPhone($user);
        $purpose = P0aOtpPurpose::StepUpInvoices;
        $resourceType = OtpStepUpGrant::RESOURCE_INVOICE;
        $resourceId = (int) $invoice->id;

        return $this->request(
            user: $user,
            phone: $phone,
            purpose: $purpose,
            resourceType: $resourceType,
            resourceId: $resourceId,
            personalAccessTokenId: $personalAccessTokenId,
            clientIp: $clientIp,
            metaExtra: [
                'order_id' => (int) $order->id,
            ],
        );
    }

    /**
     * Verify step-up OTP and issue a temporary grant (no download URL).
     *
     * @return array{grant_id: string, purpose: string, resource_type: string, resource_id: int, expires_at: string}
     *
     * @throws OtpConfigurationException
     * @throws OtpChallengeMismatchException
     */
    public function verifyResultsStepUp(
        User $user,
        LaboratoryPurchase $order,
        string $challengePublicId,
        string $code,
        ?int $personalAccessTokenId,
        ?string $clientIp,
    ): array {
        $this->assertResultsConfigurationReady();

        $purpose = P0aOtpPurpose::StepUpResults;
        $resourceType = OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE;
        $resourceId = (int) $order->id;

        $challenge = $this->verifyChallenge(
            user: $user,
            challengePublicId: $challengePublicId,
            code: $code,
            purpose: $purpose,
            resourceType: $resourceType,
            resourceId: $resourceId,
            clientIp: $clientIp,
            expectedOrderId: (int) $order->id,
        );

        $grant = $this->grantService->issue(
            challenge: $challenge,
            purpose: $purpose->value,
            resourceType: $resourceType,
            resourceId: $resourceId,
            userId: (int) $user->id,
            personalAccessTokenId: $personalAccessTokenId,
        );

        return $this->grantService->toPublicPayload($grant);
    }

    /**
     * @return array{grant_id: string, purpose: string, resource_type: string, resource_id: int, expires_at: string}
     *
     * @throws OtpConfigurationException
     * @throws OtpChallengeMismatchException
     */
    public function verifyInvoicesStepUp(
        User $user,
        LaboratoryPurchase $order,
        \App\Models\Invoice $invoice,
        string $challengePublicId,
        string $code,
        ?int $personalAccessTokenId,
        ?string $clientIp,
    ): array {
        $this->assertInvoicesConfigurationReady();

        $purpose = P0aOtpPurpose::StepUpInvoices;
        $resourceType = OtpStepUpGrant::RESOURCE_INVOICE;
        $resourceId = (int) $invoice->id;

        $challenge = $this->verifyChallenge(
            user: $user,
            challengePublicId: $challengePublicId,
            code: $code,
            purpose: $purpose,
            resourceType: $resourceType,
            resourceId: $resourceId,
            clientIp: $clientIp,
            expectedOrderId: (int) $order->id,
        );

        $grant = $this->grantService->issue(
            challenge: $challenge,
            purpose: $purpose->value,
            resourceType: $resourceType,
            resourceId: $resourceId,
            userId: (int) $user->id,
            personalAccessTokenId: $personalAccessTokenId,
        );

        return $this->grantService->toPublicPayload($grant);
    }

    /**
     * @param  array<string, mixed>  $metaExtra
     * @return array<string, mixed>
     *
     * @throws OtpDeliveryFailedException
     */
    private function request(
        User $user,
        PhoneIdentity $phone,
        P0aOtpPurpose $purpose,
        string $resourceType,
        int $resourceId,
        ?int $personalAccessTokenId,
        ?string $clientIp,
        array $metaExtra = [],
    ): array {
        unset($personalAccessTokenId);

        $subjectKey = $phone->comparisonKey();
        $destination = (string) $phone->e164();
        $ttlMinutes = (int) config('otp.p0a.policy.ttl_minutes', 5);
        $cooldown = (int) config('otp.p0a.policy.cooldown_seconds', 60);

        $data = new CreateOtpChallengeData(
            purpose: $purpose,
            channel: P0aOtpChannel::Sms,
            ttlMinutes: $ttlMinutes,
            userId: $user->id,
            subjectType: 'phone',
            subjectKey: $subjectKey,
            destinationNormalized: $destination,
            destinationMasked: $this->maskPhone($destination),
            contextType: $resourceType,
            contextId: $resourceId,
            invalidatePreviousActive: true,
            meta: array_merge([
                'flow' => 'step_up',
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
            ], $metaExtra),
            maxAttempts: (int) config('otp.p0a.policy.max_attempts', 5),
        );

        $context = new OtpRequestContext(
            purpose: $purpose,
            userId: $user->id,
            subjectType: 'phone',
            subjectKey: $subjectKey,
            contextType: $resourceType,
            contextId: $resourceId,
            channel: P0aOtpChannel::Sms,
            clientIp: $clientIp,
        );

        $result = $this->abusePolicy->issue($data, $context);
        $this->dispatchDelivery($result->challenge, $result->plainCode(), $destination);

        return $this->challengeResponsePayload($result->challenge->fresh(), $cooldown);
    }

    private function verifyChallenge(
        User $user,
        string $challengePublicId,
        string $code,
        P0aOtpPurpose $purpose,
        string $resourceType,
        int $resourceId,
        ?string $clientIp,
        ?int $expectedOrderId = null,
    ): OtpChallenge {
        $challenge = OtpChallenge::query()->where('public_id', $challengePublicId)->first();
        if ($challenge === null) {
            throw new OtpChallengeMismatchException;
        }

        if ($challenge->purpose !== $purpose->value
            || $challenge->context_type !== $resourceType
            || (int) $challenge->context_id !== $resourceId
            || (int) $challenge->user_id !== (int) $user->id
        ) {
            throw new OtpChallengeMismatchException;
        }

        if ($expectedOrderId !== null) {
            $metaOrderId = data_get($challenge->meta, 'order_id');
            if ($metaOrderId !== null && (int) $metaOrderId !== $expectedOrderId) {
                throw new OtpChallengeMismatchException;
            }
        }

        $context = new OtpRequestContext(
            purpose: $purpose,
            userId: (int) $user->id,
            subjectType: $challenge->subject_type,
            subjectKey: $challenge->subject_key,
            contextType: $resourceType,
            contextId: $resourceId,
            channel: P0aOtpChannel::tryFrom((string) $challenge->channel) ?? P0aOtpChannel::Sms,
            clientIp: $clientIp,
            existingChallengePublicId: $challengePublicId,
        );

        return $this->abusePolicy->verify($challengePublicId, $code, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeResponsePayload(OtpChallenge $challenge, int $cooldownSeconds): array
    {
        $sentAt = $challenge->last_sent_at ?? now();
        $resendAvailableAt = $sentAt->copy()->addSeconds($cooldownSeconds);

        return [
            'requires_otp' => true,
            'challenge_id' => $challenge->public_id,
            'purpose' => $challenge->purpose,
            'channel' => $challenge->channel,
            'destination_masked' => $challenge->destination_masked,
            'resource_type' => $challenge->context_type,
            'resource_id' => (int) $challenge->context_id,
            'expires_at' => $challenge->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'resend_available_at' => $resendAvailableAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @throws OtpDeliveryFailedException
     * @throws OtpConfigurationException
     */
    private function dispatchDelivery(OtpChallenge $challenge, string $plainCode, string $phoneE164): void
    {
        $outcome = $this->deliveryOrchestrator->deliverStepUpSafely(
            $challenge,
            $plainCode,
            $phoneE164,
            \App\Support\Api\V1\AkubicaCorrelationId::currentOrGenerate(),
        );

        if ($outcome === OtpDeliveryOutcome::Succeeded
            || $outcome === OtpDeliveryOutcome::DuplicateSuppressed
        ) {
            return;
        }

        if ($challenge->invalidated_at === null && $challenge->consumed_at === null) {
            $challenge->update([
                'invalidated_at' => now(),
                'invalidated_reason' => 'delivery_failed',
            ]);
        }

        throw new OtpDeliveryFailedException;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? '***' : '***'.substr($digits, -4);
    }

    private function rawPhoneFromUser(User $user): ?string
    {
        $phone = $user->phone;
        if ($phone === null) {
            return null;
        }

        if (is_object($phone) && method_exists($phone, 'formatE164')) {
            try {
                return (string) $phone->formatE164();
            } catch (\Throwable) {
                // fall through
            }
        }

        if (is_object($phone) && method_exists($phone, '__toString')) {
            return (string) $phone;
        }

        return is_scalar($phone) ? (string) $phone : null;
    }
}
