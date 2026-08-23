<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Customer;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationRecoveryContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentAuthenticationRecoveryContextManager
{
    public function __construct(
        private PaymentAuthenticationRecoveryContextDataNormalizer $normalizer,
        private PaymentAuthenticationRecoveryContextGuard $guard,
        private PaymentAuthenticationRecoveryLegacyReturnUrlParser $legacyParser,
        private PaymentAuthenticationRecoveryContextResource $resource,
        private PaymentAuthenticationRecoveryReturnBuilder $returnBuilder,
        private PaymentAuthenticationAttemptRecorder $recorder,
        private PaymentAuthenticationSensitiveCardDataStore $cardDataStore
    ) {}

    /**
     * @return array{context: PaymentAuthenticationRecoveryContext, created: bool, detected_by: string}
     */
    public function resolveForCreate(Request $request, Customer $customer): array
    {
        $this->rejectArbitraryContextType($request);

        if ($request->filled('return_url') && ! $this->legacyParser->isSafe($request->query('return_url'))) {
            throw PaymentAuthenticationRecoveryContextException::invalidReturnUrl();
        }

        $uuid = $request->query('recovery_context_uuid');

        if (is_string($uuid) && $uuid !== '') {
            $existing = $this->findOwned($customer, $uuid);

            if (! $existing) {
                throw PaymentAuthenticationRecoveryContextException::notFound();
            }

            $this->expireIfNeeded($existing);

            if ($existing->isReusable()) {
                return [
                    'context' => $existing->fresh(),
                    'created' => false,
                    'detected_by' => 'reused_uuid',
                ];
            }
        }

        $origin = $this->detectOrigin($request);

        $reusable = $this->findReusable($customer, $origin['type'], $origin['input']);

        if ($reusable) {
            return [
                'context' => $reusable,
                'created' => false,
                'detected_by' => $origin['detected_by'],
            ];
        }

        return [
            'context' => $this->createContext($customer, $origin['type'], $origin['input']),
            'created' => true,
            'detected_by' => $origin['detected_by'],
        ];
    }

    public function resolveForStore(Request $request, Customer $customer): ?PaymentAuthenticationRecoveryContext
    {
        $this->rejectArbitraryContextType($request);

        $uuid = $request->input('recovery_context_uuid');

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $context = $this->findOwned($customer, $uuid);

        if (! $context) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        $this->expireIfNeeded($context);
        $this->guard->requireAttachable($customer, $context->fresh());

        return $context->fresh();
    }

    public function contextForRetry(
        Customer $customer,
        PaymentAuthenticationAttempt $retryOfAttempt,
        ?PaymentAuthenticationRecoveryContext $submitted
    ): ?PaymentAuthenticationRecoveryContext {
        $previous = $retryOfAttempt->recoveryContext;

        if ($previous) {
            $this->guard->requireOwned($customer, $previous);
            $this->expireIfNeeded($previous);

            return $previous->fresh();
        }

        return $submitted;
    }

    public function attachAttempt(
        PaymentAuthenticationRecoveryContext $context,
        PaymentAuthenticationAttempt $attempt,
        bool $isRetry = false
    ): void {
        DB::transaction(function () use ($context, $attempt, $isRetry) {
            $locked = PaymentAuthenticationRecoveryContext::query()
                ->whereKey($context->id)
                ->lockForUpdate()
                ->firstOrFail();

            $attempt->update(['recovery_context_id' => $locked->id]);

            if (! $locked->root_authentication_attempt_id) {
                $locked->forceFill([
                    'root_authentication_attempt_id' => $attempt->id,
                ])->save();
            }

            $this->transition($locked, PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress);

            $this->recorder->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::RecoveryContextAttached, [
                'source' => 'backend',
                'dedupe_key' => 'recovery_context_attached:'.$locked->id,
                'metadata' => [
                    'context_uuid' => $locked->context_uuid,
                    'context_type' => $this->typeValue($locked),
                    'return_route_name' => $locked->return_route_name,
                    'detected_by' => $isRetry ? 'retry' : 'create_form',
                ],
            ]);

            if (! $isRetry) {
                $this->recorder->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::RecoveryContextCreated, [
                    'source' => 'backend',
                    'dedupe_key' => 'recovery_context_created:'.$locked->id,
                    'metadata' => [
                        'context_uuid' => $locked->context_uuid,
                        'context_type' => $this->typeValue($locked),
                        'return_route_name' => $locked->return_route_name,
                        'detected_by' => 'create_form',
                    ],
                ]);
            }
        });
    }

    public function syncFromAttempt(PaymentAuthenticationAttempt $attempt): void
    {
        $context = $attempt->recoveryContext;

        if (! $context) {
            return;
        }

        $this->expireIfNeeded($context);
        $context = $context->fresh();

        if (! $context || $context->isExpired() || in_array($context->status, [
            PaymentAuthenticationRecoveryContextStatus::Cancelled,
            PaymentAuthenticationRecoveryContextStatus::Recovered,
            PaymentAuthenticationRecoveryContextStatus::Expired,
        ], true)) {
            return;
        }

        $status = PaymentAuthenticationAttemptStatus::tryFrom($attempt->status);

        if (! $status) {
            return;
        }

        if (in_array($status, [
            PaymentAuthenticationAttemptStatus::Unknown,
            PaymentAuthenticationAttemptStatus::ProviderConfirmationPending,
            PaymentAuthenticationAttemptStatus::Authenticated,
            PaymentAuthenticationAttemptStatus::Tokenizing,
        ], true)) {
            $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::RecoveryBlocked, [
                'source' => 'system',
                'dedupe_key' => 'recovery_blocked:'.$context->id.':'.$status->value,
                'metadata' => [
                    'context_uuid' => $context->context_uuid,
                    'context_type' => $this->typeValue($context),
                    'detected_by' => 'attempt_status',
                ],
            ]);

            return;
        }

        if ($status === PaymentAuthenticationAttemptStatus::Completed) {
            $this->markCardVerified($context, $attempt);

            return;
        }

        if (in_array($status, [
            PaymentAuthenticationAttemptStatus::Declined,
            PaymentAuthenticationAttemptStatus::Cancelled,
            PaymentAuthenticationAttemptStatus::Expired,
            PaymentAuthenticationAttemptStatus::TechnicalError,
        ], true)) {
            $this->markRecoveryAvailable($context, $attempt);
        }
    }

    public function markCardVerified(
        PaymentAuthenticationRecoveryContext $context,
        ?PaymentAuthenticationAttempt $attempt = null
    ): void {
        $this->cardDataStore->purgeForRecoveryContext($context, 'context_card_verified', [
            'stage' => 'card_verified',
            'detected_by' => 'famedic',
        ]);

        $this->transition($context, PaymentAuthenticationRecoveryContextStatus::CardVerified, [
            'card_verified_at' => $context->card_verified_at ?? now(),
        ]);

        if ($attempt) {
            $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::CardVerified, [
                'source' => 'backend',
                'dedupe_key' => 'card_verified:'.$context->id,
                'metadata' => [
                    'context_uuid' => $context->context_uuid,
                    'context_type' => $this->typeValue($context),
                    'return_route_name' => $context->return_route_name,
                    'detected_by' => 'tokenization',
                ],
            ]);

            $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::SafeReturnGenerated, [
                'source' => 'backend',
                'dedupe_key' => 'safe_return_generated:'.$context->id,
                'metadata' => [
                    'context_uuid' => $context->context_uuid,
                    'context_type' => $this->typeValue($context),
                    'return_route_name' => $context->return_route_name,
                    'detected_by' => 'tokenization',
                ],
            ]);
        }
    }

    public function markRecoveryAvailable(
        PaymentAuthenticationRecoveryContext $context,
        PaymentAuthenticationAttempt $attempt
    ): void {
        $this->transition($context, PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable);

        $this->recorder->record($attempt, PaymentAuthenticationAttemptEventType::RecoveryAvailable, [
            'source' => 'system',
            'dedupe_key' => 'recovery_available:'.$context->id.':'.$attempt->id,
            'metadata' => [
                'context_uuid' => $context->context_uuid,
                'context_type' => $this->typeValue($context),
                'detected_by' => 'terminal_failure',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resourceFor(
        ?PaymentAuthenticationRecoveryContext $context,
        Customer $customer,
        ?PaymentAuthenticationAttempt $attempt = null
    ): ?array {
        if (! $context) {
            return null;
        }

        return $this->resource->make($context, $customer, $attempt);
    }

    public function safeReturnHref(
        Customer $customer,
        ?PaymentAuthenticationRecoveryContext $context,
        ?string $legacyReturnUrl = null
    ): ?string {
        if ($context) {
            return $this->returnBuilder->href($customer, $context);
        }

        $parsed = $this->legacyParser->parse($legacyReturnUrl);

        if (! $parsed) {
            return null;
        }

        return route($parsed['route_name'], $parsed['parameters'], false);
    }

    public function redirectAfterCardSaved(
        Customer $customer,
        ?PaymentAuthenticationRecoveryContext $context,
        string $message
    ) {
        if ($context) {
            $this->markCardVerified($context);

            return $this->returnBuilder->redirect($customer, $context->fresh(), $message);
        }

        return redirect()->route('payment-methods.index')->with('success', $message);
    }

    public function expireIfNeeded(PaymentAuthenticationRecoveryContext $context): void
    {
        if ($context->status === PaymentAuthenticationRecoveryContextStatus::Expired) {
            return;
        }

        if ($context->expires_at && $context->expires_at->isPast()) {
            $this->cardDataStore->purgeForRecoveryContext($context, 'context_expired', [
                'stage' => 'context_expire',
                'detected_by' => 'famedic',
            ]);
            $this->transition($context, PaymentAuthenticationRecoveryContextStatus::Expired);

            return;
        }

        if ($context->status !== PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress) {
            return;
        }

        if ($context->authenticationAttempts()
            ->where('status', PaymentAuthenticationAttemptStatus::Completed->value)
            ->exists()
        ) {
            return;
        }

        $activeAttempt = $context->authenticationAttempts()
            ->whereIn('status', PaymentAuthenticationAttemptStatus::activeValues())
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();

        if (! $activeAttempt) {
            $this->transition($context, PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function transition(
        PaymentAuthenticationRecoveryContext $context,
        PaymentAuthenticationRecoveryContextStatus $next,
        array $extra = []
    ): PaymentAuthenticationRecoveryContext {
        $current = $context->status instanceof PaymentAuthenticationRecoveryContextStatus
            ? $context->status
            : PaymentAuthenticationRecoveryContextStatus::tryFrom((string) $context->status);

        if ($current === $next) {
            return $context;
        }

        if ($current && ! $current->canTransitionTo($next)) {
            return $context;
        }

        $updates = array_merge([
            'status' => $next->value,
        ], $extra);

        if ($next === PaymentAuthenticationRecoveryContextStatus::Cancelled) {
            $updates['cancelled_at'] = $updates['cancelled_at'] ?? now();
        }

        $context->forceFill($updates)->save();

        return $context->fresh();
    }

    public function findOwned(Customer $customer, string $contextUuid): ?PaymentAuthenticationRecoveryContext
    {
        return PaymentAuthenticationRecoveryContext::query()
            ->where('context_uuid', $contextUuid)
            ->where('customer_id', $customer->id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createContext(
        Customer $customer,
        PaymentAuthenticationRecoveryContextType $type,
        array $input
    ): PaymentAuthenticationRecoveryContext {
        $normalized = $this->normalizer->normalize($customer, $type, $input);

        return PaymentAuthenticationRecoveryContext::create([
            'context_uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'context_type' => $type,
            'status' => PaymentAuthenticationRecoveryContextStatus::Open,
            'return_route_name' => $type->returnRouteName(),
            'context_data' => $normalized['context_data'],
            'cart_id' => $normalized['cart_id'],
            'started_at' => now(),
            'expires_at' => now()->addMinutes(max(1, (int) config('efevoopay.recovery_context_ttl_minutes', 30))),
        ]);
    }

    /**
     * @return array{type: PaymentAuthenticationRecoveryContextType, input: array<string, mixed>, detected_by: string}
     */
    private function detectOrigin(Request $request): array
    {
        $origin = $request->query('origin');

        if (is_string($origin) && $origin !== '') {
            $type = PaymentAuthenticationRecoveryContextType::fromOrigin($origin);

            if (! $type) {
                throw PaymentAuthenticationRecoveryContextException::invalidOrigin();
            }

            return [
                'type' => $type,
                'input' => $this->structuredInput($request),
                'detected_by' => 'structured_origin',
            ];
        }

        if ($request->filled('laboratory_brand')) {
            return [
                'type' => PaymentAuthenticationRecoveryContextType::LaboratoryCheckout,
                'input' => $this->structuredInput($request),
                'detected_by' => 'structured_origin',
            ];
        }

        if ($request->filled('return_url')) {
            $parsed = $this->legacyParser->parse($request->query('return_url'));

            if (! $parsed) {
                throw PaymentAuthenticationRecoveryContextException::invalidReturnUrl();
            }

            $type = PaymentAuthenticationRecoveryContextType::fromReturnRouteName($parsed['route_name']);

            if (! $type) {
                throw PaymentAuthenticationRecoveryContextException::invalidReturnUrl();
            }

            return [
                'type' => $type,
                'input' => $parsed['parameters'],
                'detected_by' => 'legacy_return_url',
            ];
        }

        return [
            'type' => PaymentAuthenticationRecoveryContextType::PaymentMethodSettings,
            'input' => [],
            'detected_by' => 'settings_default',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredInput(Request $request): array
    {
        return $request->only([
            'laboratory_brand',
            'contact',
            'contact_id',
            'address',
            'address_id',
            'appointment',
            'appointment_id',
            'coupon_id',
            'step',
            'purpose',
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function findReusable(
        Customer $customer,
        PaymentAuthenticationRecoveryContextType $type,
        array $input
    ): ?PaymentAuthenticationRecoveryContext {
        $query = PaymentAuthenticationRecoveryContext::query()
            ->where('customer_id', $customer->id)
            ->where('context_type', $type->value)
            ->whereIn('status', PaymentAuthenticationRecoveryContextStatus::reusableValues())
            ->where('expires_at', '>', now())
            ->orderByDesc('id');

        if ($type === PaymentAuthenticationRecoveryContextType::LaboratoryCheckout) {
            $brand = $input['laboratory_brand'] ?? null;

            if (is_string($brand) && $brand !== '') {
                $query->where('context_data->laboratory_brand', $brand);
            }
        }

        $existing = $query->first();

        if (! $existing) {
            return null;
        }

        $this->expireIfNeeded($existing);

        return $existing->fresh()?->isReusable() ? $existing->fresh() : null;
    }

    private function rejectArbitraryContextType(Request $request): void
    {
        if ($request->has('context_type')) {
            throw PaymentAuthenticationRecoveryContextException::invalidOrigin('El tipo de contexto no se acepta desde el cliente.');
        }
    }

    private function typeValue(PaymentAuthenticationRecoveryContext $context): string
    {
        return $context->context_type instanceof PaymentAuthenticationRecoveryContextType
            ? $context->context_type->value
            : (string) $context->context_type;
    }
}
