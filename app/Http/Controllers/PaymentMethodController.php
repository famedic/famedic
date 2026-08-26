<?php

namespace App\Http\Controllers;

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Exceptions\PaymentAuthenticationSensitiveCardDataContainmentDisabledException;
use App\Http\Requests\PaymentMethods\StartPaymentAuthenticationRecoveryRequest;
use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Models\PaymentAuthenticationAttempt;
use App\Services\CouponService;
use App\Support\AppEnvironmentLabel;
use App\Support\EfevooPay3dsResultClassifier;
use App\Support\EfevooPayLogSanitizer;
use App\Support\MockEfevooPaymentSupport;
use App\Support\PaymentAuthentication3dsCompletionService;
use App\Support\PaymentAuthentication3dsResultResource;
use App\Support\PaymentAuthentication3dsStartResource;
use App\Support\PaymentAuthenticationAttemptRecorder;
use App\Support\PaymentAuthenticationRecoveryContextException;
use App\Support\PaymentAuthenticationRecoveryContextManager;
use App\Support\PaymentAuthenticationRecoveryNavigator;
use App\Support\PaymentAuthenticationRecoveryPaymentCoordinator;
use App\Support\PaymentAuthenticationRecoveryPayPalNavigator;
use App\Support\PaymentAuthenticationRecoveryPolicy;
use App\Support\PaymentAuthenticationRecoveryStartException;
use App\Support\PaymentAuthenticationSensitiveCardDataStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PaymentMethodController extends Controller
{
    protected EfevooPayGateway $service;

    public function __construct(EfevooPayGateway $service)
    {
        $this->service = $service;
    }

    public function index(Request $request, CouponService $couponService)
    {
        $customer = $request->user()->customer;
        $balanceCents = $couponService->getUserBalance($request->user()->id);

        $tokens = EfevooToken::where('customer_id', $customer->id)
            ->currentEnvironment()
            ->active()
            ->excludeMockInProduction()
            ->orderByDesc('created_at')
            ->get();

        $paymentMethods = $tokens->unique(function (EfevooToken $token) {
            return $token->card_last_four.'-'.($token->card_expiration ?? '');
        })->values()->all();

        return Inertia::render('PaymentMethods', [
            'paymentMethods' => $paymentMethods,
            'environment' => config('efevoopay.environment'),
            'paymentUsesMock' => MockEfevooPaymentSupport::isMockMode(),
            'appEnvLabel' => AppEnvironmentLabel::current(),
            'showAppEnvBadge' => AppEnvironmentLabel::shouldShowBadge(),
            'balanceCouponsCents' => $balanceCents,
            'formattedBalanceCoupons' => $balanceCents > 0 ? formattedCentsPrice($balanceCents) : null,
        ]);
    }

    public function create(Request $request)
    {
        $customer = $request->user()->customer;

        try {
            $resolution = $this->recoveryContexts()->resolveForCreate($request, $customer);
        } catch (PaymentAuthenticationRecoveryContextException $e) {
            abort($e->status, $e->getMessage());
        }

        $recoveryContext = $this->recoveryContexts()->resourceFor($resolution['context'], $customer);
        $preparedRecovery = $this->recoveryNavigator()->consumePreparedRecovery(
            $customer,
            $resolution['context']->context_uuid
        );

        return Inertia::render('PaymentMethods/Create', [
            'returnUrl' => $recoveryContext['return_action']['href'] ?? null,
            'recoveryContext' => $recoveryContext,
            'recoveryForm' => $preparedRecovery ? [
                'recovery_action' => $preparedRecovery['recovery_action'] ?? null,
                'recovery_intent' => $preparedRecovery['recovery_intent'] ?? null,
                'context_message' => $this->recoveryFormContextMessage($resolution['context'], $preparedRecovery),
            ] : null,
            'isRecoveryForm' => $preparedRecovery !== null,
            'efevooConfig' => [
                'environment' => config('efevoopay.environment'),
                'tokenization_amount' => config('efevoopay.test_amounts.default') / 100,
                'requires_3ds' => (bool) config('efevoopay.requires_3ds') || ! MockEfevooPaymentSupport::isMockMode(),
            ],
            'hasPending3ds' => false,
            'paymentUsesMock' => MockEfevooPaymentSupport::isMockMode(),
            'paymentAuthStorageKey' => 'efevoopay:card-auth-attempt:customer-'.$customer->id,
            'mockTestCards' => MockEfevooPaymentSupport::isMockMode()
                ? $this->service->getTestCards()
                : [],
            'showAppEnvBadge' => AppEnvironmentLabel::shouldShowBadge(),
            'appEnvLabel' => AppEnvironmentLabel::current(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'card_number' => 'required|string',
            'exp_month' => 'required|string',
            'exp_year' => 'required|string',
            'cvv' => 'required|string',
            'card_holder' => 'required|string',
            'alias' => 'required|string',
            'terms_accepted' => 'accepted',
            'attempt_uuid' => 'required|uuid',
            'retry_of_attempt_id' => 'nullable|integer',
            'recovery_context_uuid' => 'nullable|uuid',
        ]);

        $customer = $request->user()->customer;
        $month = str_pad($validated['exp_month'], 2, '0', STR_PAD_LEFT);
        $year = substr($validated['exp_year'], -2);

        if ((int) $month < 1 || (int) $month > 12) {
            return back()->withErrors(['exp_month' => 'Mes invalido']);
        }

        $currentYear = date('y');
        $currentMonth = date('m');

        if (
            (int) $year < (int) $currentYear ||
            ((int) $year === (int) $currentYear && (int) $month < (int) $currentMonth)
        ) {
            return back()->withErrors(['exp_year' => 'La tarjeta esta vencida']);
        }

        $cardData = [
            'card_number' => $validated['card_number'],
            'expiration' => $month.$year,
            'cvv' => $validated['cvv'],
            'card_holder' => $validated['card_holder'],
            'alias' => $validated['alias'],
            'amount' => \App\Support\PaymentAuthenticationEfevooPayAmounts::threeDsVerificationAmount(),
        ];

        try {
            $recoveryContext = $this->recoveryContexts()->resolveForStore($request, $customer);
        } catch (PaymentAuthenticationRecoveryContextException $e) {
            return $this->recoveryContextErrorResponse($request, $e);
        }

        $preparedRecovery = $this->recoveryNavigator()->consumePreparedRecovery(
            $customer,
            $validated['recovery_context_uuid'] ?? null
        );
        $retryOfAttemptId = $preparedRecovery['retry_of_attempt_id']
            ?? ($validated['retry_of_attempt_id'] ?? null);

        if ($retryOfAttemptId) {
            $parentAttempt = PaymentAuthenticationAttempt::query()
                ->whereKey($retryOfAttemptId)
                ->where('customer_id', $customer->id)
                ->first();

            if ($parentAttempt) {
                $this->cardDataStore()->purgeByAttempt($parentAttempt, 'retry_new_attempt', [
                    'stage' => 'store',
                    'detected_by' => 'famedic',
                ]);
            }
        }

        if ($preparedRecovery && ($preparedRecovery['source_session_id'] ?? null)) {
            $this->cardDataStore()->purge((int) $preparedRecovery['source_session_id'], 'recovery_retry', null, [
                'stage' => 'store',
                'detected_by' => 'famedic',
            ]);
        }

        $this->cardDataStore()->purgeLegacyGlobal();

        if (MockEfevooPaymentSupport::isMockMode() && ! (bool) config('efevoopay.requires_3ds')) {
            $result = $this->service->tokenizeCard($cardData, $customer->id);

            if (! $result['success']) {
                return back()->withErrors([
                    'error' => $result['message'] ?? 'No se pudo registrar la tarjeta (simulacion)',
                ]);
            }

            return $this->recoveryContexts()->redirectAfterCardSaved(
                $customer,
                $recoveryContext,
                'Tarjeta de prueba registrada correctamente (sin cargo real).'
            );
        }

        try {
            $this->cardDataStore()->assertContainmentEnabledForStorage();
        } catch (PaymentAuthenticationSensitiveCardDataContainmentDisabledException $e) {
            Log::error('[3DS] Card verification blocked — containment disabled', [
                'customer_id' => $customer->id,
            ]);

            return back()->withErrors(['error' => $e->getMessage()]);
        }

        Log::info('[3DS] Iniciando proceso', [
            'customer_id' => $customer->id,
            'attempt_uuid' => $validated['attempt_uuid'],
        ]);

        $attemptResolution = $this->resolveAuthenticationAttempt(
            $customer,
            $validated['attempt_uuid'],
            $retryOfAttemptId,
            $recoveryContext
        );

        if ($attemptResolution['error'] ?? null) {
            return $this->authenticationAttemptErrorResponse($request, $attemptResolution);
        }

        if ($preparedRecovery && ($attemptResolution['attempt'] ?? null)) {
            $this->recoveryNavigator()->clearPreparedRecovery($customer);
        }

        /** @var PaymentAuthenticationAttempt $attempt */
        $attempt = $attemptResolution['attempt'];

        if (! ($attemptResolution['should_call_provider'] ?? false)) {
            $session = $attempt->efevoo3dsSession;

            if ($session && (int) $session->customer_id === (int) $customer->id) {
                $this->recorder()->record($attempt, PaymentAuthenticationAttemptEventType::AttemptReused, [
                    'source' => 'backend',
                    'dedupe_key' => 'attempt_reused:redirect:'.$attempt->duplicate_request_count,
                    'metadata' => ['session_id' => $session->id],
                ]);

                return redirect()->route('payment-methods.3ds-redirect', [
                    'sessionId' => $session->id,
                ]);
            }

            $this->recorder()->record($attempt, PaymentAuthenticationAttemptEventType::AttemptReused, [
                'source' => 'backend',
                'dedupe_key' => 'attempt_reused:pending:'.$attempt->duplicate_request_count,
                'metadata' => ['response_received' => false],
            ]);

            return back()->with('info', 'Estamos confirmando el estado de tu verificacion. Referencia: '.$attempt->support_reference);
        }

        $claimed = PaymentAuthenticationAttempt::query()
            ->whereKey($attempt->id)
            ->where('status', PaymentAuthenticationAttemptStatus::Created->value)
            ->update([
                'status' => PaymentAuthenticationAttemptStatus::Initiating->value,
                'last_provider_call_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            $attempt->increment('duplicate_request_count');
            $this->recorder()->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::DuplicateRequestBlocked, [
                'source' => 'backend',
                'result_category' => EfevooPay3dsResultClassifier::CATEGORY_DUPLICATE_REQUEST,
                'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC,
                'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED,
                'dedupe_key' => 'duplicate_claim_blocked:'.$attempt->duplicate_request_count,
            ]);

            return back()->with('info', 'Estamos confirmando el estado de tu verificacion. Referencia: '.$attempt->support_reference);
        }

        $attempt = $attempt->fresh();
        $this->recorder()->record($attempt, PaymentAuthenticationAttemptEventType::ProviderLinkRequestStarted, [
            'source' => 'backend',
            'status_from' => PaymentAuthenticationAttemptStatus::Created->value,
            'status_to' => PaymentAuthenticationAttemptStatus::Initiating->value,
            'external_operation' => 'payments3DS_GetLink',
            'dedupe_key' => 'provider_link_request_started:1',
            'metadata' => [
                'external_operation' => 'payments3DS_GetLink',
                'operation' => 'payments3DS_GetLink',
                'amount' => \App\Support\PaymentAuthenticationEfevooPayAmounts::threeDsVerificationAmount(),
                'currency' => \App\Support\PaymentAuthenticationEfevooPayAmounts::currency(),
                'call_number' => 1,
            ],
        ]);

        $providerStartedAt = microtime(true);

        try {
            $result = $this->service->initiate3DS(
                \App\Support\PaymentAuthenticationEfevooPayAmounts::forGetLink($cardData),
                $customer->id
            );
        } catch (\Throwable $e) {
            Log::warning('[3DS] Resultado ambiguo iniciando proveedor', [
                'attempt_id' => $attempt->id,
                'support_reference' => $attempt->support_reference,
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            $durationMs = (int) round((microtime(true) - $providerStartedAt) * 1000);
            $classification = [
                'result_category' => EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_TIMEOUT,
                'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_NETWORK,
                'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_UNKNOWN,
                'provider_message' => 'Estamos confirmando el estado de tu verificacion',
            ];
            $attempt = $attempt->fresh();

            $this->recorder()->transition($attempt, PaymentAuthenticationAttemptStatus::ProviderConfirmationPending, PaymentAuthenticationAttemptEventType::ProviderLinkRequestTimeout, array_merge($classification, [
                'source' => 'backend',
                'external_call_number' => $attempt->provider_link_call_count,
                'duration_ms' => $durationMs,
                'dedupe_key' => 'provider_link_request_timeout:1',
                'metadata' => [
                    'exception_class' => $e::class,
                    'timeout_stage' => 'payments3DS_GetLink',
                    'response_received' => false,
                ],
            ]));

            $this->recorder()->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::ProviderConfirmationPending, array_merge($classification, [
                'source' => 'system',
                'dedupe_key' => 'provider_confirmation_pending:1',
            ]));

            $attempt->fresh()->update([
                'provider_message' => 'Estamos confirmando el estado de tu verificacion',
            ]);

            return back()->withErrors([
                'error' => 'Estamos confirmando el estado de tu verificacion. Referencia: '.$attempt->support_reference,
            ]);
        }

        if (! $result['success']) {
            $this->markAttemptFromProviderFailure($attempt, $result, (int) round((microtime(true) - $providerStartedAt) * 1000));

            return back()->withErrors([
                'error' => $result['message'] ?? 'Error iniciando verificacion',
            ]);
        }

        $session = Efevoo3dsSession::where('id', $result['session_id'])
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $session->update([
            'payment_authentication_attempt_id' => $attempt->id,
        ]);

        $durationMs = (int) round((microtime(true) - $providerStartedAt) * 1000);
        $classification = EfevooPay3dsResultClassifier::providerLink($result);

        $this->recorder()->transition($attempt, PaymentAuthenticationAttemptStatus::ChallengeRequired, PaymentAuthenticationAttemptEventType::ProviderLinkRequestSucceeded, [
            'source' => 'efevoopay',
            'result_category' => $classification['result_category'],
            'failure_origin' => $classification['failure_origin'],
            'failure_certainty' => $classification['failure_certainty'],
            'provider_status' => $classification['provider_status'],
            'provider_code' => $classification['provider_code'],
            'provider_message' => $classification['provider_message'],
            'external_call_number' => $attempt->fresh()->provider_link_call_count,
            'duration_ms' => $durationMs,
            'dedupe_key' => 'provider_link_request_succeeded:1',
            'metadata' => [
                'session_id' => $session->id,
                'response_received' => true,
            ],
        ]);

        $attempt->fresh()->update([
            'efevoo_3ds_session_id' => $session->id,
            'provider_order_id' => $session->order_id,
            'provider_code' => '0',
            'provider_message' => null,
        ]);

        $this->recorder()->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::ThreeDsSessionCreated, [
            'source' => 'backend',
            'dedupe_key' => '3ds_session_created:'.$session->id,
            'metadata' => ['session_id' => $session->id],
        ]);
        $this->recorder()->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::ChallengeReady, [
            'source' => 'backend',
            'dedupe_key' => 'challenge_ready:'.$session->id,
            'metadata' => ['session_id' => $session->id],
        ]);

        $this->cardDataStore()->store($customer, $session, $attempt->fresh(), $cardData);

        return redirect()->route('payment-methods.3ds-redirect', [
            'sessionId' => $session->id,
        ]);
    }

    public function showMock3ds(Request $request, $sessionId)
    {
        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        return Inertia::render('PaymentMethods/MockThreeDS', [
            'sessionId' => $session->id,
            'cardLastFour' => $session->card_last_four,
            'amount' => $session->amount,
            'showAppEnvBadge' => AppEnvironmentLabel::shouldShowBadge(),
            'appEnvLabel' => AppEnvironmentLabel::current(),
        ]);
    }

    public function show3dsRedirect(Request $request, $sessionId)
    {
        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $attempt = $session->paymentAuthenticationAttempt;

        return Inertia::render('PaymentMethods/ThreeDSRedirect', [
            'sessionId' => $session->id,
            'url3ds' => $session->url_3dsecure,
            'token3ds' => $session->token_3dsecure,
            'authenticationAttempt' => $attempt
                ? PaymentAuthentication3dsStartResource::make($attempt, $session)
                : null,
        ]);
    }

    public function show3dsResult(Request $request, $sessionId)
    {
        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $this->syncAuthenticationAttemptFromSession($session);

        $attempt = $session->paymentAuthenticationAttempt;
        $recoveryContext = $attempt?->recoveryContext;

        if ($attempt && $recoveryContext) {
            $this->recoveryContexts()->syncFromAttempt($attempt->fresh());
            $recoveryContext = $recoveryContext->fresh();
        }

        $result = app(PaymentAuthentication3dsResultResource::class)->make(
            $session,
            $customer,
            $attempt?->fresh(),
            $recoveryContext
        );

        return Inertia::render('PaymentMethods/ThreeDSResult', [
            'sessionId' => $session->id,
            'result' => $result,
            'paymentAuthStorageKey' => 'efevoopay:card-auth-attempt:customer-'.$customer->id,
            // Legacy props kept for gradual migration in embedded consumers/tests.
            'success' => $result['success'],
            'message' => $result['copy']['message'] ?? null,
            'errorDetail' => $result['provider_message'] ?? null,
            'status' => $result['status'] ?? $session->status,
            'cardLastFour' => $result['card_last_four'] ?? null,
            'amount' => $result['verification_charge']['amount'] ?? null,
            'createdAt' => $session->created_at,
            'returnUrl' => $result['recovery']['return_action']['href'] ?? null,
            'recoveryContext' => $result['recovery'],
        ]);
    }

    public function refresh3dsResultStatus(Request $request, $sessionId)
    {
        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $attempt = $session->paymentAuthenticationAttempt?->fresh();
        $recoveryContext = $attempt?->recoveryContext?->fresh();

        $result = app(PaymentAuthentication3dsResultResource::class)->make(
            $session,
            $customer,
            $attempt,
            $recoveryContext
        );

        return response()->json(['result' => $result]);
    }

    public function sync3dsResultStatus(Request $request, $sessionId)
    {
        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        if (in_array($session->status, ['completed', 'declined', 'cancelled', 'tokenization_failed', 'error', 'failed'], true)) {
            $this->syncAuthenticationAttemptFromSession($session);
        }

        $attempt = $session->paymentAuthenticationAttempt?->fresh();
        $recoveryContext = $attempt?->recoveryContext?->fresh();

        if ($attempt && $recoveryContext) {
            $this->recoveryContexts()->syncFromAttempt($attempt);
            $recoveryContext = $recoveryContext->fresh();
        }

        if ($attempt) {
            $windowMinutes = max(1, (int) config('efevoopay.recovery.status_refresh_dedupe_minutes', 5));
            $bucket = (int) floor(now()->timestamp / ($windowMinutes * 60));

            $this->recorder()->record($attempt, PaymentAuthenticationAttemptEventType::RecoveryStatusRefreshed, [
                'source' => 'frontend',
                'dedupe_key' => 'recovery_status_refreshed:'.$attempt->id.':'.$bucket,
                'metadata' => [
                    'context_uuid' => $recoveryContext?->context_uuid,
                    'context_type' => $recoveryContext?->context_type?->value ?? $recoveryContext?->context_type,
                    'detected_by' => 'result_sync',
                ],
            ]);
        }

        $result = app(PaymentAuthentication3dsResultResource::class)->make(
            $session->fresh(),
            $customer,
            $attempt,
            $recoveryContext
        );

        return response()->json(['result' => $result]);
    }

    public function startPayPalRecovery(\App\Http\Requests\StartPaymentAuthenticationRecoveryPayPalRequest $request)
    {
        $customer = $request->user()->customer;
        $validated = $request->validated();

        $session = Efevoo3dsSession::query()
            ->whereKey($validated['session_id'])
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $context = \App\Models\PaymentAuthenticationRecoveryContext::query()
            ->where('context_uuid', $validated['recovery_context_uuid'])
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        try {
            $navigation = app(PaymentAuthenticationRecoveryPayPalNavigator::class)->start(
                $customer,
                $session,
                $context
            );
        } catch (PaymentAuthenticationRecoveryStartException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'error' => $exception->error,
                    'evaluation' => $exception->evaluation,
                ], $exception->status);
            }

            return back()
                ->withErrors(['error' => $exception->getMessage()])
                ->setStatusCode($exception->status);
        }

        if ($request->expectsJson()) {
            return response()->json($navigation);
        }

        return redirect()->to($navigation['redirect_url']);
    }

    public function cancelPayPalRecovery(\App\Http\Requests\CancelPaymentAuthenticationRecoveryPayPalRequest $request)
    {
        $customer = $request->user()->customer;
        $validated = $request->validated();

        $context = \App\Models\PaymentAuthenticationRecoveryContext::query()
            ->where('context_uuid', $validated['recovery_context_uuid'])
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $transaction = null;
        if (! empty($validated['transaction_id'])) {
            $transaction = \App\Models\Transaction::query()
                ->whereKey($validated['transaction_id'])
                ->where('payment_method', 'paypal')
                ->firstOrFail();

            $details = is_array($transaction->details) ? $transaction->details : [];
            if ((int) ($details['customer_id'] ?? 0) !== (int) $customer->id) {
                abort(404);
            }

            if (($details['recovery_context_uuid'] ?? null) !== $context->context_uuid) {
                abort(404);
            }

            if (($transaction->payment_status ?? '') === 'captured') {
                return response()->json(['status' => 'already_captured']);
            }
        }

        $attempt = app(PaymentAuthenticationRecoveryPaymentCoordinator::class)->resolveAttemptForContext($context);

        if (! $attempt || (int) $attempt->customer_id !== (int) $customer->id) {
            abort(404);
        }

        app(PaymentAuthenticationRecoveryPaymentCoordinator::class)->releaseAfterPayPalCancel(
            $context,
            $transaction,
            $attempt
        );

        return response()->json(['status' => 'released']);
    }

    public function startRecovery(StartPaymentAuthenticationRecoveryRequest $request)
    {
        $customer = $request->user()->customer;
        $validated = $request->validated();

        $session = Efevoo3dsSession::query()
            ->whereKey($validated['session_id'])
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $context = \App\Models\PaymentAuthenticationRecoveryContext::query()
            ->where('context_uuid', $validated['recovery_context_uuid'])
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        try {
            $navigation = $this->recoveryNavigator()->start(
                $customer,
                $session,
                $context,
                $validated['recovery_action']
            );
        } catch (PaymentAuthenticationRecoveryStartException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'error' => $exception->error,
                    'evaluation' => $exception->evaluation,
                    'active_attempt' => isset($exception->evaluation['active_attempt'])
                        ? PaymentAuthentication3dsStartResource::make($exception->evaluation['active_attempt'])
                        : null,
                ], $exception->status);
            }

            return back()
                ->withErrors(['error' => $exception->getMessage()])
                ->setStatusCode($exception->status);
        }

        if ($request->expectsJson()) {
            return response()->json($navigation);
        }

        return redirect()->to($navigation['redirect_url']);
    }

    public function destroy(Request $request, $tokenId)
    {
        $customer = $request->user()->customer;

        $token = EfevooToken::where('id', $tokenId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $token->update([
            'is_active' => false,
            'deleted_at' => now(),
        ]);

        return back()->with('success', 'Tarjeta eliminada correctamente');
    }

    public function health()
    {
        return response()->json(
            $this->service->healthCheck()
        );
    }

    public function check3dsStatus(Request $request, $sessionId)
    {
        Log::info('[3DS] check3dsStatus llamado', [
            'session_id' => $sessionId,
        ]);

        $customer = $request->user()->customer;

        $session = Efevoo3dsSession::where('id', $sessionId)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $attempt = $session->paymentAuthenticationAttempt;

        if ($session->status === 'mock_pending') {
            $this->recordLegacySessionIfNeeded($session);
        }

        $willPollExternally = $attempt
            && ! in_array($session->status, ['completed', 'declined', 'tokenization_failed', 'cancelled', 'error', 'failed'], true)
            && ! in_array($attempt->status, [
                PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
                PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value,
            ], true);

        if ($willPollExternally) {
            $this->recordStatusPollStarted($attempt);
        }

        $pollResult = $this->completionService()->poll($customer, $session, $attempt);

        $session->refresh();
        $success = ($pollResult['status'] ?? '') === 'completed';
        $this->recordStatusPollResult($attempt, $session, $success, $pollResult);

        if (! in_array($pollResult['status'] ?? '', [
            PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
            PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value,
            PaymentAuthenticationAttemptStatus::TechnicalError->value,
        ], true) && ! in_array($session->status, ['pending', 'mock_pending'], true)) {
            $this->syncAuthenticationAttemptFromSession($session);
        }

        $message = $session->error_message
            ?? $pollResult['message']
            ?? $this->resolveStatusMessage($session->status);

        return response()->json([
            'final' => (bool) ($pollResult['final'] ?? false),
            'status' => $pollResult['status'] ?? $session->status,
            'message' => $message,
            'error_detail' => $session->error_message,
            'error_type' => $pollResult['error_type'] ?? null,
        ]);
    }

    private function resolveStatusMessage(string $status): string
    {
        return match ($status) {
            'completed' => 'Tarjeta verificada y guardada correctamente.',
            'declined' => 'La verificacion fue rechazada por tu banco. Puede deberse a que cancelaste el proceso o el banco no autorizo la operacion.',
            'tokenization_failed' => 'La tarjeta fue autenticada, pero no pudo guardarse. Revisa el motivo mas abajo o contacta a soporte.',
            'authenticated' => 'Verificacion exitosa. Guardando tarjeta...',
            'pending' => 'Esperando que completes la verificacion en la ventana de tu banco.',
            default => 'Procesando verificacion...',
        };
    }

    private function resolveAuthenticationAttempt(
        Customer $customer,
        string $attemptUuid,
        mixed $retryOfAttemptId = null,
        ?\App\Models\PaymentAuthenticationRecoveryContext $recoveryContext = null
    ): array {
        return DB::transaction(function () use ($customer, $attemptUuid, $retryOfAttemptId, $recoveryContext) {
            $existing = PaymentAuthenticationAttempt::where('attempt_uuid', $attemptUuid)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->customer_id !== (int) $customer->id) {
                    Log::warning('[3DS] UUID collision blocked', [
                        'attempt_uuid' => $attemptUuid,
                    ]);

                    return [
                        'error' => 'uuid_collision',
                        'message' => 'No se pudo iniciar la verificacion de tarjeta.',
                        'status' => 422,
                    ];
                }

                $existing->increment('duplicate_request_count');

                if (
                    $existing->expires_at
                    && $existing->expires_at->isPast()
                    && in_array($existing->status, PaymentAuthenticationAttemptStatus::activeValues(), true)
                ) {
                    $classification = EfevooPay3dsResultClassifier::localExpiration();
                    $this->recorder()->transition($existing, PaymentAuthenticationAttemptStatus::Expired, PaymentAuthenticationAttemptEventType::AttemptExpired, [
                        'source' => 'system',
                        'result_category' => $classification['result_category'],
                        'failure_origin' => $classification['failure_origin'],
                        'failure_certainty' => $classification['failure_certainty'],
                        'dedupe_key' => 'attempt_expired:'.$existing->id,
                        'metadata' => $classification['metadata'] ?? ['detected_by' => 'famedic'],
                    ]);

                    return [
                        'error' => 'attempt_expired',
                        'message' => 'La verificacion anterior expiro. Intenta nuevamente.',
                        'status' => 409,
                        'attempt' => $existing->fresh(),
                    ];
                }

                $this->recorder()->record($existing->fresh(), PaymentAuthenticationAttemptEventType::AttemptReused, [
                    'source' => 'backend',
                    'result_category' => EfevooPay3dsResultClassifier::CATEGORY_DUPLICATE_REQUEST,
                    'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC,
                    'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED,
                    'dedupe_key' => 'attempt_reused:'.$existing->duplicate_request_count,
                ]);

                return [
                    'attempt' => $existing->fresh(['efevoo3dsSession']),
                    'should_call_provider' => false,
                ];
            }

            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            PaymentAuthenticationAttempt::query()
                ->where('customer_id', $customer->id)
                ->where('operation_type', PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS)
                ->whereIn('status', PaymentAuthenticationAttemptStatus::activeValues())
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get()
                ->each(function (PaymentAuthenticationAttempt $expiredAttempt) {
                    $classification = EfevooPay3dsResultClassifier::localExpiration();
                    $this->recorder()->transition($expiredAttempt, PaymentAuthenticationAttemptStatus::Expired, PaymentAuthenticationAttemptEventType::AttemptExpired, [
                        'source' => 'system',
                        'result_category' => $classification['result_category'],
                        'failure_origin' => $classification['failure_origin'],
                        'failure_certainty' => $classification['failure_certainty'],
                        'dedupe_key' => 'attempt_expired:'.$expiredAttempt->id,
                        'metadata' => $classification['metadata'] ?? ['detected_by' => 'famedic'],
                    ]);
                });

            $active = PaymentAuthenticationAttempt::query()
                ->where('customer_id', $customer->id)
                ->where('operation_type', PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS)
                ->whereIn('status', PaymentAuthenticationAttemptStatus::activeValues())
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->lockForUpdate()
                ->first();

            if ($active) {
                $active->increment('duplicate_request_count');

                $this->recorder()->record($active->fresh(), PaymentAuthenticationAttemptEventType::ConcurrentAttemptBlocked, [
                    'source' => 'backend',
                    'result_category' => EfevooPay3dsResultClassifier::CATEGORY_CONCURRENT_ATTEMPT,
                    'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC,
                    'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED,
                    'dedupe_key' => 'concurrent_attempt_blocked:'.$active->duplicate_request_count,
                ]);

                return [
                    'error' => 'active_attempt_exists',
                    'message' => 'Ya tienes una verificacion de tarjeta en proceso.',
                    'status' => 409,
                    'attempt' => $active->fresh(),
                ];
            }

            $retryOfAttempt = null;

            if ($retryOfAttemptId) {
                $retryOfAttempt = PaymentAuthenticationAttempt::query()
                    ->whereKey($retryOfAttemptId)
                    ->where('customer_id', $customer->id)
                    ->lockForUpdate()
                    ->first();

                if (! $retryOfAttempt || ! $retryOfAttempt->isRecoverableTerminal()) {
                    return [
                        'error' => 'retry_not_allowed',
                        'message' => 'La verificacion actual no permite reintento en este momento.',
                        'status' => 409,
                    ];
                }

                $recoveryContext = $this->recoveryContexts()->contextForRetry($customer, $retryOfAttempt, $recoveryContext);
            }

            $attempt = PaymentAuthenticationAttempt::create([
                'attempt_uuid' => $attemptUuid,
                'support_reference' => $this->uniqueAuthenticationReference('AUTH'),
                'customer_id' => $customer->id,
                'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
                'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
                'status' => PaymentAuthenticationAttemptStatus::Created->value,
                'merchant_reference' => $this->uniqueAuthenticationReference('EFV3DS'),
                'retry_of_attempt_id' => $retryOfAttempt?->id,
                'recovery_context_id' => $recoveryContext?->id,
                'attempt_number' => $retryOfAttempt ? $retryOfAttempt->attempt_number + 1 : 1,
                'initiated_by' => 'customer',
                'started_at' => now(),
                'expires_at' => now()->addMinutes(max(1, (int) config('efevoopay.authentication_attempt_ttl_minutes', 5))),
            ]);

            if ($recoveryContext) {
                $this->recoveryContexts()->attachAttempt($recoveryContext, $attempt, $retryOfAttempt !== null);
            }

            $this->recorder()->record($attempt, PaymentAuthenticationAttemptEventType::AttemptCreated, [
                'source' => 'backend',
                'status_to' => PaymentAuthenticationAttemptStatus::Created->value,
                'dedupe_key' => 'attempt_created',
                'metadata' => [
                    'previous_attempt_id' => $retryOfAttempt?->id,
                    'retry_attempt_number' => $attempt->attempt_number,
                ],
            ]);

            if ($retryOfAttempt) {
                $this->recorder()->record($retryOfAttempt, PaymentAuthenticationAttemptEventType::ManualRetryCreated, [
                    'source' => 'backend',
                    'dedupe_key' => 'manual_retry_created:'.$attempt->id,
                    'metadata' => [
                        'previous_attempt_id' => $retryOfAttempt->id,
                        'retry_attempt_number' => $attempt->attempt_number,
                    ],
                ]);
            }

            return [
                'attempt' => $attempt,
                'should_call_provider' => true,
            ];
        });
    }

    private function authenticationAttemptErrorResponse(Request $request, array $resolution)
    {
        $attempt = $resolution['attempt'] ?? null;
        $payload = null;

        if ($attempt instanceof PaymentAuthenticationAttempt) {
            $payload = ($resolution['error'] ?? null) === 'attempt_expired'
                ? [
                    'attempt_uuid' => $attempt->attempt_uuid,
                    'support_reference' => $attempt->support_reference,
                    'status' => $attempt->status,
                    'expires_at' => $attempt->expires_at?->toISOString(),
                ]
                : PaymentAuthentication3dsStartResource::make($attempt);

            $session = $attempt->efevoo3dsSession;

            if ($session) {
                $payload['result_url'] = route('payment-methods.3ds-result', ['sessionId' => $session->id]);
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $resolution['message'],
                'attempt' => $payload,
            ], $resolution['status'] ?? 409);
        }

        return back()
            ->withErrors(['error' => $resolution['message']])
            ->with('payment_authentication_attempt', $payload)
            ->setStatusCode($resolution['status'] ?? 409);
    }

    private function markAttemptFromProviderFailure(PaymentAuthenticationAttempt $attempt, array $result, int $durationMs): void
    {
        $classification = EfevooPay3dsResultClassifier::providerLink($result);
        $status = $classification['internal_status'];
        $eventType = $classification['requires_provider_confirmation']
            ? PaymentAuthenticationAttemptEventType::ProviderLinkRequestTimeout
            : PaymentAuthenticationAttemptEventType::ProviderLinkRequestFailed;

        $this->recorder()->transition($attempt, $status, $eventType, [
            'source' => $classification['origin'] === EfevooPay3dsResultClassifier::ORIGIN_NETWORK ? 'system' : 'efevoopay',
            'result_category' => $classification['result_category'],
            'failure_origin' => $classification['failure_origin'],
            'failure_certainty' => $classification['failure_certainty'],
            'provider_status' => $classification['provider_status'],
            'provider_code' => $classification['provider_code'],
            'provider_message' => $classification['provider_message'],
            'external_call_number' => $attempt->fresh()->provider_link_call_count,
            'duration_ms' => $durationMs,
            'dedupe_key' => $eventType->value.':1',
            'metadata' => [
                'external_operation' => 'payments3DS_GetLink',
                'response_received' => ! $classification['requires_provider_confirmation'],
            ],
        ]);

        if ($classification['requires_provider_confirmation']) {
            $this->recorder()->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::ProviderConfirmationPending, [
                'source' => 'system',
                'result_category' => $classification['result_category'],
                'failure_origin' => $classification['failure_origin'],
                'failure_certainty' => $classification['failure_certainty'],
                'provider_status' => $classification['provider_status'],
                'provider_code' => $classification['provider_code'],
                'provider_message' => $classification['provider_message'],
                'dedupe_key' => 'provider_confirmation_pending:1',
            ]);
        }

        $this->recoveryContexts()->syncFromAttempt($attempt->fresh());
    }

    private function syncAuthenticationAttemptFromSession(
        Efevoo3dsSession $session,
        ?PaymentAuthenticationAttemptStatus $forcedStatus = null
    ): void {
        $attempt = $session->paymentAuthenticationAttempt;

        if (! $attempt) {
            return;
        }

        if (in_array($attempt->status, [
            PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value,
            PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value,
        ], true) && in_array($session->status, ['pending', 'mock_pending'], true)) {
            $this->recoveryContexts()->syncFromAttempt($attempt->fresh());

            return;
        }

        $status = $forcedStatus ?? match ($session->status) {
            'completed' => PaymentAuthenticationAttemptStatus::Completed,
            'declined' => PaymentAuthenticationAttemptStatus::Declined,
            'cancelled' => PaymentAuthenticationAttemptStatus::Cancelled,
            'tokenization_failed', 'error', 'failed' => PaymentAuthenticationAttemptStatus::TechnicalError,
            'authenticated' => PaymentAuthenticationAttemptStatus::Authenticated,
            'pending', 'mock_pending' => PaymentAuthenticationAttemptStatus::Pending,
            default => PaymentAuthenticationAttemptStatus::ChallengeRequired,
        };

        $eventType = match ($status) {
            PaymentAuthenticationAttemptStatus::Completed => PaymentAuthenticationAttemptEventType::AttemptCompleted,
            PaymentAuthenticationAttemptStatus::Declined => PaymentAuthenticationAttemptEventType::AuthenticationDeclined,
            PaymentAuthenticationAttemptStatus::Cancelled => PaymentAuthenticationAttemptEventType::AuthenticationCancelled,
            PaymentAuthenticationAttemptStatus::Expired => PaymentAuthenticationAttemptEventType::AuthenticationExpired,
            PaymentAuthenticationAttemptStatus::Authenticated => PaymentAuthenticationAttemptEventType::AuthenticationSucceeded,
            PaymentAuthenticationAttemptStatus::Tokenizing => PaymentAuthenticationAttemptEventType::TokenizationStarted,
            PaymentAuthenticationAttemptStatus::TechnicalError => PaymentAuthenticationAttemptEventType::TechnicalError,
            default => PaymentAuthenticationAttemptEventType::ProviderStatusReceived,
        };

        $classification = EfevooPay3dsResultClassifier::providerStatus(
            $session->status,
            null,
            $session->error_message
        );

        if ($attempt->status === $status->value) {
            if (in_array($status->value, PaymentAuthenticationAttemptStatus::terminalValues(), true)) {
                if ($status === PaymentAuthenticationAttemptStatus::Completed
                    && $attempt->failure_category !== EfevooPay3dsResultClassifier::CATEGORY_SUCCESS) {
                    $attempt->forceFill([
                        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_SUCCESS,
                        'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_EFEVOOPAY,
                        'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED,
                    ])->save();
                }

                $this->recoveryContexts()->syncFromAttempt($attempt->fresh());

                return;
            }

            $this->recorder()->record($attempt, $eventType, [
                'source' => 'polling',
                'provider_status' => $session->status,
                'provider_message' => $session->error_message,
                'result_category' => $classification['result_category'],
                'failure_origin' => $classification['failure_origin'],
                'failure_certainty' => $classification['failure_certainty'],
                'dedupe_key' => $eventType->value.':same_status:'.$session->status.':'.$session->status_checked_at?->timestamp,
                'metadata' => ['session_id' => $session->id],
            ]);

            $this->recoveryContexts()->syncFromAttempt($attempt->fresh());

            return;
        }

        $this->recorder()->transition($attempt, $status, $eventType, [
            'source' => 'polling',
            'provider_status' => $session->status,
            'provider_message' => $session->error_message,
            'provider_code' => $classification['provider_code'],
            'result_category' => $classification['result_category'],
            'failure_origin' => $classification['failure_origin'],
            'failure_certainty' => $classification['failure_certainty'],
            'finished_at' => $session->completed_at ?? null,
            'dedupe_key' => $eventType->value.':transition:'.$attempt->status.':'.$status->value,
            'metadata' => ['session_id' => $session->id],
        ]);

        $attempt->fresh()->update([
            'provider_order_id' => $session->order_id,
        ]);

        $this->recoveryContexts()->syncFromAttempt($attempt->fresh());
    }

    private function uniqueAuthenticationReference(string $prefix): string
    {
        do {
            $reference = $prefix.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
        } while (PaymentAuthenticationAttempt::where('support_reference', $reference)
            ->orWhere('merchant_reference', $reference)
            ->exists());

        return $reference;
    }

    private function recordStatusPollStarted(?PaymentAuthenticationAttempt $attempt): void
    {
        if (! $attempt) {
            return;
        }

        $this->recorder()->record($attempt, PaymentAuthenticationAttemptEventType::StatusPollStarted, [
            'source' => 'polling',
            'dedupe_key' => 'status_poll_started:internal:'.($attempt->events()->where('event_type', PaymentAuthenticationAttemptEventType::StatusPollStarted->value)->count() + 1),
            'metadata' => [
                'stage' => 'internal_poll',
            ],
        ]);
    }

    private function recordStatusPollResult(
        ?PaymentAuthenticationAttempt $attempt,
        Efevoo3dsSession $session,
        bool $success,
        array $result = []
    ): void {
        if (! $attempt) {
            return;
        }

        $errorType = $result['error_type'] ?? null;
        $ambiguous = ($result['status'] ?? null) === PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value
            || ($result['exception_category'] ?? null) === 'provider_confirmation_pending';
        $receivedProviderStatus = filled($session->status) && $session->status !== 'mock_pending';
        $pollFailed = in_array($errorType, ['network', 'timeout'], true)
            || $ambiguous
            || (! $success && ! $receivedProviderStatus);
        $eventType = $pollFailed
            ? PaymentAuthenticationAttemptEventType::StatusPollFailed
            : PaymentAuthenticationAttemptEventType::StatusPollSucceeded;
        $classification = EfevooPay3dsResultClassifier::providerStatus(
            $ambiguous ? null : $session->status,
            data_get($result, 'raw.data.status.code'),
            $session->error_message ?? ($result['message'] ?? null)
        );

        $this->recorder()->record($attempt->fresh(), $eventType, [
            'source' => 'polling',
            'provider_status' => $ambiguous ? null : $session->status,
            'provider_code' => $classification['provider_code'],
            'provider_message' => $classification['provider_message'],
            'result_category' => $classification['result_category'],
            'failure_origin' => $classification['failure_origin'],
            'failure_certainty' => $classification['failure_certainty'],
            'dedupe_key' => $eventType->value.':'.$attempt->status_poll_call_count.':'.$session->status,
            'metadata' => [
                'session_id' => $session->id,
                'poll_number' => $attempt->fresh()->status_poll_call_count,
                'response_received' => ! $pollFailed,
            ],
        ]);

        if ($receivedProviderStatus && ! $ambiguous) {
            $this->recorder()->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::ProviderStatusReceived, [
                'source' => 'efevoopay',
                'provider_status' => $session->status,
                'provider_code' => $classification['provider_code'],
                'provider_message' => $classification['provider_message'],
                'result_category' => $classification['result_category'],
                'failure_origin' => $classification['failure_origin'],
                'failure_certainty' => $classification['failure_certainty'],
                'dedupe_key' => 'provider_status_received:'.$attempt->status_poll_call_count.':'.$session->status,
                'metadata' => [
                    'session_id' => $session->id,
                    'poll_number' => $attempt->fresh()->status_poll_call_count,
                    'response_received' => true,
                ],
            ]);
        }

        if (in_array($session->status, ['authenticated', 'approved', 'completed'], true)) {
            $this->recorder()->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::AuthenticationSucceeded, [
                'source' => 'efevoopay',
                'provider_status' => $session->status,
                'provider_code' => $classification['provider_code'],
                'provider_message' => $classification['provider_message'],
                'dedupe_key' => 'authentication_succeeded:'.$session->id.':'.$session->status,
                'metadata' => ['session_id' => $session->id],
            ]);
        }

        if ($session->status === 'completed') {
            $this->recorder()->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::TokenizationStarted, [
                'source' => 'backend',
                'dedupe_key' => 'tokenization_started:'.$session->id,
                'metadata' => ['session_id' => $session->id, 'stage' => 'post_poll'],
            ]);
            $this->recorder()->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::TokenizationSucceeded, [
                'source' => 'efevoopay',
                'dedupe_key' => 'tokenization_succeeded:'.$session->id,
                'metadata' => ['session_id' => $session->id],
            ]);
        }

        if ($session->status === 'tokenization_failed') {
            $classification = EfevooPay3dsResultClassifier::tokenization([
                'success' => false,
                'message' => $session->error_message,
            ]);
            $this->recorder()->record($attempt->fresh(), PaymentAuthenticationAttemptEventType::TokenizationFailed, [
                'source' => 'efevoopay',
                'result_category' => $classification['result_category'],
                'failure_origin' => $classification['failure_origin'],
                'failure_certainty' => $classification['failure_certainty'],
                'provider_message' => $classification['provider_message'],
                'dedupe_key' => 'tokenization_failed:'.$session->id,
                'metadata' => ['session_id' => $session->id],
            ]);
        }
    }

    private function recordLegacySessionIfNeeded(Efevoo3dsSession $session): void
    {
        if ($session->paymentAuthenticationAttempt) {
            return;
        }

        Log::info('[3DS Auth] Legacy session without authentication attempt', [
            'session_id' => $session->id,
        ]);
    }

    private function recorder(): PaymentAuthenticationAttemptRecorder
    {
        return app(PaymentAuthenticationAttemptRecorder::class);
    }

    private function cardDataStore(): PaymentAuthenticationSensitiveCardDataStore
    {
        return app(PaymentAuthenticationSensitiveCardDataStore::class);
    }

    private function completionService(): PaymentAuthentication3dsCompletionService
    {
        return app(PaymentAuthentication3dsCompletionService::class);
    }

    private function recoveryContexts(): PaymentAuthenticationRecoveryContextManager
    {
        return app(PaymentAuthenticationRecoveryContextManager::class);
    }

    private function recoveryNavigator(): PaymentAuthenticationRecoveryNavigator
    {
        return app(PaymentAuthenticationRecoveryNavigator::class);
    }

    private function recoveryFormContextMessage(
        \App\Models\PaymentAuthenticationRecoveryContext $context,
        array $preparedRecovery
    ): string {
        $action = $preparedRecovery['recovery_action'] ?? PaymentAuthenticationRecoveryPolicy::ACTION_RETRY;

        if ($action === PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD) {
            return 'Ingresa los datos de la otra tarjeta que deseas verificar.';
        }

        return 'Vuelve a ingresar los datos de tu tarjeta para intentar la verificación nuevamente.';
    }

    private function recoveryContextErrorResponse(Request $request, PaymentAuthenticationRecoveryContextException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->status);
        }

        if ($exception->status === 404) {
            abort(404);
        }

        return back()
            ->withErrors(['error' => $exception->getMessage()])
            ->setStatusCode($exception->status);
    }
}
