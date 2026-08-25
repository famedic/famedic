<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationAttemptDateRange;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationEfevooPayOperationAnalyzer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PaymentAuthenticationAttemptAdminResource
{
    /**
     * @return array<string, mixed>
     */
    public static function listItem(PaymentAuthenticationAttempt $attempt, ?array $recovery = null): array
    {
        return [
            'id' => $attempt->id,
            'attempt_uuid' => $attempt->attempt_uuid,
            'support_reference' => $attempt->support_reference,
            'customer' => self::customer($attempt),
            'email' => $attempt->customer?->user?->email,
            'status' => $attempt->status,
            'result_category' => $attempt->failure_category,
            'failure_origin' => $attempt->failure_origin,
            'failure_certainty' => $attempt->failure_certainty,
            'origin_label' => self::originLabel($attempt->failure_origin, $attempt->failure_certainty),
            'attempt_number' => $attempt->attempt_number,
            'started_at' => $attempt->started_at?->toISOString(),
            'started_at_local' => self::localDate($attempt->started_at),
            'finished_at' => $attempt->finished_at?->toISOString(),
            'finished_at_local' => self::localDate($attempt->finished_at),
            'duration_seconds' => $attempt->durationSeconds(),
            'external_call_count' => $attempt->external_call_count,
            'provider_link_call_count' => $attempt->provider_link_call_count,
            'status_poll_call_count' => $attempt->status_poll_call_count,
            'tokenization_call_count' => $attempt->tokenization_call_count,
            'duplicate_request_count' => $attempt->duplicate_request_count,
            'has_previous_retry' => $attempt->retry_of_attempt_id !== null,
            'has_later_retry' => ((int) ($attempt->retry_attempts_count ?? 0)) > 0,
            'provider_order_id' => self::sanitizeIdentifier($attempt->provider_order_id),
            'provider_code' => $attempt->provider_code,
            'provider_message' => self::truncatedMessage($attempt->provider_message),
            'provider' => $attempt->provider,
            'badge' => self::badge($attempt),
            'stage_label' => self::stageLabel($attempt),
            'recovery' => $recovery,
        ];
    }

    /**
     * @param  Collection<int, PaymentAuthenticationAttempt>  $chain
     * @return array<string, mixed>
     */
    public static function detail(
        PaymentAuthenticationAttempt $attempt,
        Collection $events,
        Collection $chain,
        ?array $recoveryDetail = null,
        ?array $efevooPayOperations = null,
    ): array {
        $previous = $chain->firstWhere('id', $attempt->retry_of_attempt_id);
        $next = $chain->where('retry_of_attempt_id', $attempt->id)->values();
        $final = $chain->last();
        $root = $chain->first();
        $recovered = $root
            && in_array($root->status, PaymentAuthenticationAttemptStatus::recoverableTerminalValues(), true)
            && $final?->status === PaymentAuthenticationAttemptStatus::Completed->value;

        return array_merge(self::listItem($attempt), [
            'merchant_reference' => $attempt->merchant_reference,
            'operation_type' => $attempt->operation_type,
            'initiated_by' => $attempt->initiated_by,
            'recovery_context_type' => $attempt->recoveryContext?->context_type?->value
                ?? $attempt->recoveryContext?->context_type
                ?? PaymentAuthenticationRecoveryContextType::UNKNOWN,
            'recovery_context' => self::recoveryContextSummary($attempt),
            'recovery_detail' => $recoveryDetail,
            'recovery_intention' => self::recoveryIntention($events),
            'recovery_limit_reached' => $events->contains(
                fn (PaymentAuthenticationAttemptEvent $event) => $event->event_type === PaymentAuthenticationAttemptEventType::RecoveryLimitReached->value
            ),
            'expires_at_local' => self::localDate($attempt->expires_at),
            'retry_of_attempt_id' => $attempt->retry_of_attempt_id,
            'previous_attempt' => $previous ? self::chainItem($previous) : null,
            'later_attempts' => $next->map(fn (PaymentAuthenticationAttempt $item) => self::chainItem($item))->values(),
            'retry_chain' => $chain->map(fn (PaymentAuthenticationAttempt $item) => self::chainItem($item))->values(),
            'chain_final_status' => $final?->status,
            'chain_recovered' => $recovered,
            'efevoopay_operations' => $efevooPayOperations ?? app(PaymentAuthenticationEfevooPayOperationAnalyzer::class)->analyze($attempt),
            'events' => $events->map(fn (PaymentAuthenticationAttemptEvent $event) => PaymentAuthenticationAttemptEventAdminResource::make($event))->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function chainItem(PaymentAuthenticationAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'support_reference' => $attempt->support_reference,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status,
            'result_category' => $attempt->failure_category,
            'started_at_local' => self::localDate($attempt->started_at),
            'badge' => self::badge($attempt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function exportRow(PaymentAuthenticationAttempt $attempt, ?array $recovery = null): array
    {
        $item = self::listItem($attempt, $recovery);
        $operations = app(PaymentAuthenticationEfevooPayOperationAnalyzer::class)->analyze($attempt);

        return [
            'support_reference' => $item['support_reference'],
            'attempt_uuid' => $item['attempt_uuid'],
            'customer_id' => $attempt->customer_id,
            'email' => $item['email'],
            'status' => $item['status'],
            'result_category' => $item['result_category'],
            'failure_origin' => $item['failure_origin'],
            'failure_certainty' => $item['failure_certainty'],
            'attempt_number' => $item['attempt_number'],
            'started_at_local' => $item['started_at_local'],
            'finished_at_local' => $item['finished_at_local'],
            'duration_seconds' => $item['duration_seconds'],
            'external_call_count' => $item['external_call_count'],
            'provider_link_call_count' => $item['provider_link_call_count'],
            'status_poll_call_count' => $item['status_poll_call_count'],
            'tokenization_call_count' => $item['tokenization_call_count'],
            'duplicate_request_count' => $item['duplicate_request_count'],
            'provider_order_id' => $item['provider_order_id'],
            'provider_code' => $item['provider_code'],
            'provider_message' => $item['provider_message'],
            'recovered_on_retry' => $item['has_previous_retry'] && $attempt->status === PaymentAuthenticationAttemptStatus::Completed->value,
            'provider' => $item['provider'],
            'recovery_context_type' => $recovery['context_type'] ?? PaymentAuthenticationRecoveryContextType::UNKNOWN,
            'recovery_context_status' => $recovery['context_status'] ?? null,
            'recovery_eligible' => ($recovery['recovery_eligible'] ?? false) ? 'sí' : 'no',
            'recovery_started' => ($recovery['recovery_started'] ?? false) ? 'sí' : 'no',
            'selected_recovery_action' => $recovery['selected_intention'] ?? null,
            'authentication_recovered' => ($recovery['authentication_recovered'] ?? false) ? 'sí' : 'no',
            'payment_recovered' => ($recovery['payment_recovered'] ?? false) ? 'sí' : 'no',
            'recovery_method_confirmed' => $recovery['confirmed_method'] ?? null,
            'attempt_count_in_context' => $recovery['chain_attempt_count'] ?? null,
            'card_verified_at_local' => $recovery['card_verified_at_local'] ?? null,
            'recovered_at_local' => $recovery['recovered_at_local'] ?? null,
            'time_to_authentication_recovery_seconds' => $recovery['time_to_authentication_recovery_seconds'] ?? null,
            'time_to_payment_recovery_seconds' => $recovery['time_to_recovery_seconds'] ?? null,
            'internal_transaction_id' => $recovery['recovered_transaction_id'] ?? $recovery['recovery_transaction_id'] ?? null,
            'limit_reached' => ($recovery['limit_reached'] ?? false) ? 'sí' : 'no',
            'confirmation_pending' => ($recovery['confirmation_pending'] ?? false) ? 'sí' : 'no',
            'possible_duplicate_verification' => ($operations['possible_duplicate_verification_operation'] ?? false) ? 'sí' : 'no',
        ];
    }

    /**
     * @return array{id: int|null, name: string|null, email: string|null}
     */
    public static function customer(PaymentAuthenticationAttempt $attempt): array
    {
        $user = $attempt->customer?->user;

        return [
            'id' => $attempt->customer_id,
            'name' => $user?->full_name,
            'email' => $user?->email,
        ];
    }

    public static function originLabel(?string $origin, ?string $certainty): string
    {
        if ($origin === EfevooPay3dsResultClassifier::ORIGIN_ISSUER
            && $certainty === EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED) {
            return 'Banco';
        }

        if (! $origin || $origin === EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN) {
            return 'Origen no determinado por el proveedor';
        }

        return match ($origin) {
            EfevooPay3dsResultClassifier::ORIGIN_USER => 'Usuario',
            EfevooPay3dsResultClassifier::ORIGIN_ACS => 'ACS',
            EfevooPay3dsResultClassifier::ORIGIN_EFEVOOPAY => 'EfevooPay',
            EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC => 'FAMEDIC',
            EfevooPay3dsResultClassifier::ORIGIN_NETWORK => 'Red',
            default => 'Origen no determinado por el proveedor',
        };
    }

    /**
     * @return array{tone: string, label: string}
     */
    public static function badge(PaymentAuthenticationAttempt $attempt): array
    {
        if (in_array($attempt->status, PaymentAuthenticationAttemptStatus::activeValues(), true)
            && $attempt->status !== PaymentAuthenticationAttemptStatus::Unknown->value
            && $attempt->status !== PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value) {
            return ['tone' => 'active', 'label' => 'Activo'];
        }

        return match ($attempt->status) {
            PaymentAuthenticationAttemptStatus::Completed->value => ['tone' => 'success', 'label' => 'Éxito'],
            PaymentAuthenticationAttemptStatus::Declined->value => ['tone' => 'declined', 'label' => 'Rechazado'],
            PaymentAuthenticationAttemptStatus::Cancelled->value => ['tone' => 'cancelled', 'label' => 'Cancelado'],
            PaymentAuthenticationAttemptStatus::Expired->value => ['tone' => 'expired', 'label' => 'Expirado'],
            PaymentAuthenticationAttemptStatus::TechnicalError->value => ['tone' => 'technical', 'label' => 'Error técnico'],
            PaymentAuthenticationAttemptStatus::Unknown->value,
            PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value => ['tone' => 'unknown', 'label' => 'Pendiente / unknown'],
            default => ['tone' => 'active', 'label' => 'Activo'],
        };
    }

    public static function stageLabel(PaymentAuthenticationAttempt $attempt): string
    {
        return match ($attempt->status) {
            PaymentAuthenticationAttemptStatus::Created->value => 'Creado',
            PaymentAuthenticationAttemptStatus::Initiating->value => 'Iniciando',
            PaymentAuthenticationAttemptStatus::ChallengeRequired->value => 'Challenge',
            PaymentAuthenticationAttemptStatus::Pending->value => 'Pendiente',
            PaymentAuthenticationAttemptStatus::Authenticated->value => 'Autenticado',
            PaymentAuthenticationAttemptStatus::Tokenizing->value => 'Tokenizando',
            PaymentAuthenticationAttemptStatus::Completed->value => 'Completado',
            PaymentAuthenticationAttemptStatus::Declined->value => 'Autenticación',
            PaymentAuthenticationAttemptStatus::Cancelled->value => 'Cancelación',
            PaymentAuthenticationAttemptStatus::Expired->value => 'Expiración',
            PaymentAuthenticationAttemptStatus::TechnicalError->value => 'Error técnico',
            PaymentAuthenticationAttemptStatus::Unknown->value => 'Sin confirmar',
            PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value => 'Confirmación pendiente',
            PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value => 'Tokenización en confirmación',
            default => $attempt->status,
        };
    }

    public static function eventLabel(string $eventType): string
    {
        return match ($eventType) {
            PaymentAuthenticationAttemptEventType::AttemptCreated->value => 'Intento creado',
            PaymentAuthenticationAttemptEventType::AttemptReused->value => 'Intento reutilizado',
            PaymentAuthenticationAttemptEventType::DuplicateRequestBlocked->value => 'Duplicado bloqueado',
            PaymentAuthenticationAttemptEventType::ConcurrentAttemptBlocked->value => 'Intento concurrente bloqueado',
            PaymentAuthenticationAttemptEventType::AttemptExpired->value => 'Intento expirado',
            PaymentAuthenticationAttemptEventType::ManualRetryCreated->value => 'Reintento manual creado',
            PaymentAuthenticationAttemptEventType::ProviderLinkRequestStarted->value => 'Llamada GetLink iniciada',
            PaymentAuthenticationAttemptEventType::ProviderLinkRequestSucceeded->value => 'Respuesta GetLink recibida',
            PaymentAuthenticationAttemptEventType::ProviderLinkRequestFailed->value => 'GetLink falló',
            PaymentAuthenticationAttemptEventType::ProviderLinkRequestTimeout->value => 'Timeout de GetLink',
            PaymentAuthenticationAttemptEventType::ThreeDsSessionCreated->value => 'Sesión 3DS creada',
            PaymentAuthenticationAttemptEventType::ChallengeReady->value => 'Challenge listo',
            PaymentAuthenticationAttemptEventType::ChallengePresented->value => 'Challenge presentado',
            PaymentAuthenticationAttemptEventType::ChallengeSubmissionStarted->value => 'Envío de challenge iniciado',
            PaymentAuthenticationAttemptEventType::StatusPollStarted->value => 'Consulta de estatus iniciada',
            PaymentAuthenticationAttemptEventType::StatusPollSucceeded->value => 'Consulta de estatus exitosa',
            PaymentAuthenticationAttemptEventType::StatusPollFailed->value => 'Consulta de estatus fallida',
            PaymentAuthenticationAttemptEventType::ProviderStatusReceived->value => 'Estatus del proveedor recibido',
            PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value => 'Autenticación exitosa',
            PaymentAuthenticationAttemptEventType::AuthenticationDeclined->value => 'Autenticación rechazada',
            PaymentAuthenticationAttemptEventType::AuthenticationExpired->value => 'Autenticación expirada',
            PaymentAuthenticationAttemptEventType::AuthenticationCancelled->value => 'Autenticación cancelada',
            PaymentAuthenticationAttemptEventType::TokenizationStarted->value => 'Tokenización iniciada',
            PaymentAuthenticationAttemptEventType::TokenizationSucceeded->value => 'Tokenización exitosa',
            PaymentAuthenticationAttemptEventType::TokenizationFailed->value => 'Tokenización fallida',
            PaymentAuthenticationAttemptEventType::AttemptCompleted->value => 'Intento completado',
            PaymentAuthenticationAttemptEventType::TechnicalError->value => 'Error técnico',
            PaymentAuthenticationAttemptEventType::NetworkError->value => 'Error de red',
            PaymentAuthenticationAttemptEventType::ProviderConfirmationPending->value => 'Confirmación del proveedor pendiente',
            PaymentAuthenticationAttemptEventType::StateConflictDetected->value => 'Conflicto de estado',
            PaymentAuthenticationAttemptEventType::SafeReturnGenerated->value => 'Retorno seguro generado',
            PaymentAuthenticationAttemptEventType::RecoveryStarted->value => 'Recuperación iniciada',
            PaymentAuthenticationAttemptEventType::ChangedCard->value => 'El usuario seleccionó usar otra tarjeta',
            PaymentAuthenticationAttemptEventType::RecoveryRetryBlocked->value => 'Reintento bloqueado',
            PaymentAuthenticationAttemptEventType::RecoveryLimitReached->value => 'Límite de intentos alcanzado',
            PaymentAuthenticationAttemptEventType::RecoveryStatusRefreshed->value => 'Estado actualizado desde resultado',
            PaymentAuthenticationAttemptEventType::ChangedToPaypal->value => 'Cambió a PayPal',
            PaymentAuthenticationAttemptEventType::PaypalOrderRequestStarted->value => 'Solicitud de orden PayPal iniciada',
            PaymentAuthenticationAttemptEventType::PaypalOrderCreated->value => 'Orden PayPal creada',
            PaymentAuthenticationAttemptEventType::PaypalOrderReused->value => 'Orden PayPal reutilizada',
            PaymentAuthenticationAttemptEventType::PaypalOrderTimeout->value => 'Timeout al crear orden PayPal',
            PaymentAuthenticationAttemptEventType::PaypalApprovedByUser->value => 'PayPal aprobado por el usuario',
            PaymentAuthenticationAttemptEventType::PaypalCaptureStarted->value => 'Captura PayPal iniciada',
            PaymentAuthenticationAttemptEventType::PaypalCaptureSucceeded->value => 'Captura PayPal completada',
            PaymentAuthenticationAttemptEventType::PaypalCaptureFailed->value => 'Captura PayPal fallida',
            PaymentAuthenticationAttemptEventType::PaypalCaptureReused->value => 'Captura PayPal reutilizada',
            PaymentAuthenticationAttemptEventType::PaypalCancelled->value => 'Canceló PayPal',
            PaymentAuthenticationAttemptEventType::RecoveryPaymentStarted->value => 'Recuperación de pago iniciada',
            PaymentAuthenticationAttemptEventType::RecoveryCompleted->value => 'Recuperación completada',
            PaymentAuthenticationAttemptEventType::RecoveryPaymentFailed->value => 'Recuperación de pago fallida',
            PaymentAuthenticationAttemptEventType::RecoveryConfirmationPending->value => 'Confirmación pendiente',
            PaymentAuthenticationAttemptEventType::RecoveryContextCreated->value => 'Contexto de recuperación creado',
            PaymentAuthenticationAttemptEventType::RecoveryContextAttached->value => 'Contexto de recuperación vinculado',
            PaymentAuthenticationAttemptEventType::RecoveryAvailable->value => 'Recuperación disponible',
            PaymentAuthenticationAttemptEventType::RecoveryBlocked->value => 'Recuperación bloqueada',
            PaymentAuthenticationAttemptEventType::CardVerified->value => 'Tarjeta verificada',
            PaymentAuthenticationAttemptEventType::SensitiveCardDataStored->value => 'Datos sensibles almacenados temporalmente',
            PaymentAuthenticationAttemptEventType::SensitiveCardDataPurged->value => 'Datos sensibles purgados',
            PaymentAuthenticationAttemptEventType::SensitiveCardDataExpired->value => 'Datos sensibles expirados',
            PaymentAuthenticationAttemptEventType::SensitiveCardDataMissing->value => 'Datos sensibles ausentes',
            PaymentAuthenticationAttemptEventType::ProviderStatusRequestStarted->value => 'GetStatus iniciado',
            PaymentAuthenticationAttemptEventType::ProviderStatusRequestSucceeded->value => 'GetStatus respondió',
            PaymentAuthenticationAttemptEventType::ProviderStatusRequestFailed->value => 'GetStatus falló',
            PaymentAuthenticationAttemptEventType::ProviderStatusRequestTimeout->value => 'Timeout GetStatus',
            PaymentAuthenticationAttemptEventType::TokenizationRequestStarted->value => 'TokenCard iniciado',
            PaymentAuthenticationAttemptEventType::TokenizationRequestSucceeded->value => 'TokenCard respondió',
            PaymentAuthenticationAttemptEventType::TokenizationRequestFailed->value => 'TokenCard falló',
            PaymentAuthenticationAttemptEventType::TokenizationRequestTimeout->value => 'Timeout TokenCard',
            PaymentAuthenticationAttemptEventType::TokenizationConfirmationPending->value => 'Tokenización en confirmación',
            PaymentAuthenticationAttemptEventType::ExistingTokenReused->value => 'Token existente reutilizado',
            PaymentAuthenticationAttemptEventType::DuplicateExternalCallBlocked->value => 'Llamada externa duplicada bloqueada',
            PaymentAuthenticationAttemptEventType::PaymentOperationCorrelationDetected->value => 'Correlación de operación detectada',
            PaymentAuthenticationAttemptEventType::PossibleDuplicateVerificationOperation->value => 'Posible operación de verificación duplicada',
            default => $eventType,
        };
    }

    public static function eventKind(PaymentAuthenticationAttemptEvent $event): string
    {
        $type = $event->event_type;

        if (in_array($type, [
            PaymentAuthenticationAttemptEventType::DuplicateRequestBlocked->value,
            PaymentAuthenticationAttemptEventType::AttemptReused->value,
            PaymentAuthenticationAttemptEventType::ConcurrentAttemptBlocked->value,
            PaymentAuthenticationAttemptEventType::DuplicateExternalCallBlocked->value,
        ], true)) {
            return 'duplicate';
        }

        if ($type === PaymentAuthenticationAttemptEventType::ManualRetryCreated->value) {
            return 'retry';
        }

        if (in_array($type, [
            PaymentAuthenticationAttemptEventType::RecoveryStarted->value,
            PaymentAuthenticationAttemptEventType::ChangedCard->value,
            PaymentAuthenticationAttemptEventType::ChangedToPaypal->value,
        ], true)) {
            return 'intention';
        }

        if (in_array($type, [
            PaymentAuthenticationAttemptEventType::CardVerified->value,
        ], true)) {
            return 'verified';
        }

        if (in_array($type, [
            PaymentAuthenticationAttemptEventType::RecoveryCompleted->value,
            PaymentAuthenticationAttemptEventType::PaypalCaptureSucceeded->value,
        ], true)) {
            return 'confirmed';
        }

        if (in_array($type, [
            PaymentAuthenticationAttemptEventType::RecoveryConfirmationPending->value,
            PaymentAuthenticationAttemptEventType::PaypalOrderTimeout->value,
            PaymentAuthenticationAttemptEventType::ProviderConfirmationPending->value,
            PaymentAuthenticationAttemptEventType::TokenizationConfirmationPending->value,
        ], true)) {
            return 'pending';
        }

        if (in_array($type, [
            PaymentAuthenticationAttemptEventType::PaypalOrderRequestStarted->value,
            PaymentAuthenticationAttemptEventType::PaypalCaptureStarted->value,
            PaymentAuthenticationAttemptEventType::RecoveryPaymentStarted->value,
        ], true)) {
            return 'operation';
        }

        if (in_array($type, [
            PaymentAuthenticationAttemptEventType::RecoveryRetryBlocked->value,
            PaymentAuthenticationAttemptEventType::RecoveryLimitReached->value,
            PaymentAuthenticationAttemptEventType::PaypalOrderCreated->value,
            PaymentAuthenticationAttemptEventType::PaypalOrderReused->value,
            PaymentAuthenticationAttemptEventType::PaypalApprovedByUser->value,
            PaymentAuthenticationAttemptEventType::PaypalCaptureFailed->value,
            PaymentAuthenticationAttemptEventType::PaypalCaptureReused->value,
            PaymentAuthenticationAttemptEventType::PaypalCancelled->value,
            PaymentAuthenticationAttemptEventType::RecoveryPaymentFailed->value,
        ], true)) {
            return 'recovery';
        }

        if (str_ends_with($type, '_failed')
            || in_array($type, [
                PaymentAuthenticationAttemptEventType::TechnicalError->value,
                PaymentAuthenticationAttemptEventType::NetworkError->value,
                PaymentAuthenticationAttemptEventType::ProviderLinkRequestTimeout->value,
                PaymentAuthenticationAttemptEventType::StateConflictDetected->value,
            ], true)) {
            return 'error';
        }

        if (str_ends_with($type, '_started') || $type === PaymentAuthenticationAttemptEventType::ChallengeSubmissionStarted->value) {
            return 'call_started';
        }

        if (str_ends_with($type, '_succeeded')
            || $type === PaymentAuthenticationAttemptEventType::ProviderStatusReceived->value) {
            return 'response_received';
        }

        if ($event->status_from && $event->status_to && $event->status_from !== $event->status_to) {
            return 'transition';
        }

        return 'transition';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function recoveryContextSummary(PaymentAuthenticationAttempt $attempt): ?array
    {
        $context = $attempt->recoveryContext;

        if (! $context) {
            return null;
        }

        return [
            'context_uuid' => $context->context_uuid,
            'context_type' => $context->context_type?->value ?? $context->context_type,
            'status' => $context->status?->value ?? $context->status,
            'recovery_method' => $context->recovery_method,
            'recovery_transaction_id' => $context->recovery_transaction_id,
            'recovered_transaction_id' => $context->recovered_transaction_id,
            'recovered_at' => $context->recovered_at?->toISOString(),
            'recovered_at_local' => self::localDate($context->recovered_at),
        ];
    }

    private static function localDate(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }

        return $date->copy()->timezone(PaymentAuthenticationAttemptDateRange::TIMEZONE)->isoFormat('D MMM Y, HH:mm:ss');
    }

    /**
     * @param  Collection<int, PaymentAuthenticationAttemptEvent>  $events
     */
    private static function recoveryIntention(Collection $events): ?string
    {
        $changedCard = $events->firstWhere('event_type', PaymentAuthenticationAttemptEventType::ChangedCard->value);

        if ($changedCard) {
            return 'El usuario seleccionó usar otra tarjeta';
        }

        $recoveryStarted = $events->firstWhere('event_type', PaymentAuthenticationAttemptEventType::RecoveryStarted->value);

        if ($recoveryStarted) {
            $action = data_get($recoveryStarted->allowlistedMetadata(), 'recovery_action');

            return $action === PaymentAuthenticationRecoveryPolicy::RECOVERY_INTENT_DIFFERENT_CARD
                ? 'El usuario seleccionó usar otra tarjeta'
                : 'El usuario inició un reintento';
        }

        return null;
    }

    private static function truncatedMessage(?string $message): ?string
    {
        if (! $message) {
            return null;
        }

        return Str::limit(EfevooPayLogSanitizer::providerMessage($message) ?? '', 180, '');
    }

    private static function sanitizeIdentifier(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return EfevooPayLogSanitizer::providerMessage($value);
    }
}
