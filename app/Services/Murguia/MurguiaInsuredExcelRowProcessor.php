<?php

namespace App\Services\Murguia;

use App\Actions\Customers\GenerateUniqueMedicalAttentionIdAction;
use App\Actions\Customers\CreateRegularAccountCustomerAction;
use App\Actions\MedicalAttention\CheckStatusAction;
use App\Actions\MedicalAttention\CreateRegularSubscriptionAction;
use App\Actions\MedicalAttention\SyncSubscriptionToMurguiaAction;
use App\Actions\MedicalAttention\UpdateStatusAction;
use App\Actions\Users\CreateUserAction;
use App\Enums\MedicalSubscriptionType;
use App\Models\Customer;
use App\Models\MurguiaSyncLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MurguiaInsuredExcelRowProcessor
{
    public function __construct(
        private CheckStatusAction $checkStatusAction,
        private UpdateStatusAction $updateStatusAction,
        private CreateRegularSubscriptionAction $createRegularSubscriptionAction,
        private SyncSubscriptionToMurguiaAction $syncSubscriptionToMurguiaAction,
        private CreateUserAction $createUserAction,
        private CreateRegularAccountCustomerAction $createRegularAccountCustomerAction,
        private GenerateUniqueMedicalAttentionIdAction $generateUniqueMedicalAttentionIdAction,
    ) {}

    /**
     * @param  array<string, mixed>  $row  Normalizado: email, medical_attention_identifier, accion
     */
    public function process(array $row, int $rowNumber): void
    {
        $email = isset($row['email']) ? trim((string) $row['email']) : '';
        $identifier = isset($row['medical_attention_identifier']) && $row['medical_attention_identifier'] !== ''
            ? trim((string) $row['medical_attention_identifier'])
            : null;
        $accion = isset($row['accion']) ? mb_strtolower(trim((string) $row['accion'])) : '';

        $accion = match ($accion) {
            'alta' => MurguiaSyncLog::ACTION_ALTA,
            'baja' => MurguiaSyncLog::ACTION_BAJA,
            'validacion', 'validación' => MurguiaSyncLog::ACTION_VALIDACION,
            default => $accion,
        };

        if ($identifier !== null && ! $this->generateUniqueMedicalAttentionIdAction->isValidIdentifier($identifier)) {
            $this->log(
                null,
                null,
                null,
                $accion ?: 'unknown',
                [],
                null,
                MurguiaSyncLog::STATUS_FAILED,
                'murguia.identifier.invalid'
            );

            return;
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->log(
                null,
                null,
                null,
                $accion ?: 'unknown',
                [],
                null,
                MurguiaSyncLog::STATUS_FAILED,
                'murguia.email.invalid'
            );

            return;
        }

        if (! in_array($accion, [
            MurguiaSyncLog::ACTION_ALTA,
            MurguiaSyncLog::ACTION_BAJA,
            MurguiaSyncLog::ACTION_VALIDACION,
        ], true)) {
            $this->log(
                null,
                null,
                null,
                $accion ?: 'unknown',
                [],
                null,
                MurguiaSyncLog::STATUS_FAILED,
                'murguia.action.invalid'
            );

            return;
        }

        try {
            match ($accion) {
                MurguiaSyncLog::ACTION_VALIDACION => $this->runValidacion($email, $identifier, $rowNumber),
                MurguiaSyncLog::ACTION_BAJA => $this->runBaja($email, $identifier, $rowNumber),
                MurguiaSyncLog::ACTION_ALTA => $this->runAlta($email, $identifier, $rowNumber),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error('Murguia Excel row failed', [
                'email_present' => $email !== '',
                'error_type' => $e::class,
            ]);

            $this->log(
                null,
                null,
                null,
                $accion,
                [],
                ['exception_type' => $e::class],
                MurguiaSyncLog::STATUS_FAILED,
                'murguia.row.failed'
            );
        }
    }

    public function findCustomer(string $email, ?string $identifier): ?Customer
    {
        if ($identifier) {
            $byId = Customer::query()
                ->where('medical_attention_identifier', $identifier)
                ->first();

            if ($byId) {
                return $byId;
            }
        }

        return Customer::query()
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->first();
    }

    private function runValidacion(string $email, ?string $identifier, int $rowNumber): void
    {
        $customer = $this->findCustomer($email, $identifier);

        if (! $customer) {
            $this->log(
                null,
                null,
                null,
                MurguiaSyncLog::ACTION_VALIDACION,
                [],
                null,
                MurguiaSyncLog::STATUS_NOT_FOUND,
                'murguia.validation.customer_not_found'
            );

            return;
        }

        $response = ($this->checkStatusAction)($customer);
        $body = $response->json() ?? [];

        $interpretation = $this->interpretCheckStatus($body);

        $this->log(
            $customer->id,
            null,
            null,
            MurguiaSyncLog::ACTION_VALIDACION,
            [],
            ['http_status' => $response->status(), 'result_code' => $interpretation],
            $response->successful() ? MurguiaSyncLog::STATUS_SUCCESS : MurguiaSyncLog::STATUS_FAILED,
            'murguia.validation.completed'
        );
    }

    private function runBaja(string $email, ?string $identifier, int $rowNumber): void
    {
        $customer = $this->findCustomer($email, $identifier);

        if (! $customer) {
            $this->log(
                null,
                null,
                null,
                MurguiaSyncLog::ACTION_BAJA,
                [],
                null,
                MurguiaSyncLog::STATUS_NOT_FOUND,
                'murguia.deactivation.customer_not_found'
            );

            return;
        }

        $response = ($this->updateStatusAction)($customer, 'inactivo');

        $this->log(
            $customer->id,
            null,
            null,
            MurguiaSyncLog::ACTION_BAJA,
            [],
            ['http_status' => $response->status(), 'result_code' => $response->successful() ? 'success' : 'failed'],
            $response->successful() ? MurguiaSyncLog::STATUS_SUCCESS : MurguiaSyncLog::STATUS_FAILED,
            'murguia.deactivation.completed'
        );
    }

    private function runAlta(string $email, ?string $identifier, int $rowNumber): void
    {
        $customer = $this->findCustomer($email, $identifier);

        if (! $customer) {
            if ($identifier && Customer::where('medical_attention_identifier', $identifier)->exists()) {
                $this->log(
                    null,
                    null,
                    null,
                    MurguiaSyncLog::ACTION_ALTA,
                    [],
                    null,
                    MurguiaSyncLog::STATUS_FAILED,
                    'murguia.activation.identifier_conflict'
                );

                return;
            }

            $user = User::where('email', $email)->first();

            if (! $user) {
                $user = ($this->createUserAction)($email);
            }

            $user->refresh();

            if (! $user->customer) {
                ($this->createRegularAccountCustomerAction)($user);
                $user->load('customer');
            }

            $customer = $user->customer;

            if (! $customer) {
                $this->log(
                    null,
                    null,
                    null,
                    MurguiaSyncLog::ACTION_ALTA,
                    [],
                    null,
                    MurguiaSyncLog::STATUS_FAILED,
                    'murguia.activation.customer_unavailable'
                );

                return;
            }

            if ($identifier) {
                if (! $this->generateUniqueMedicalAttentionIdAction->reserveExistingIdentifier(
                    $identifier,
                    'murguia_provider_assignment',
                    Customer::class,
                    $customer->id,
                )) {
                    $this->log(
                        $customer->id,
                        null,
                        null,
                        MurguiaSyncLog::ACTION_ALTA,
                        [],
                        null,
                        MurguiaSyncLog::STATUS_FAILED,
                        'murguia.activation.identifier_reserved'
                    );

                    return;
                }

                $customer->update(['medical_attention_identifier' => $identifier]);
            }
        }

        $subscription = $customer->medicalAttentionSubscriptions()
            ->orderByDesc('end_date')
            ->first();

        if (! $subscription) {
            $subscription = ($this->createRegularSubscriptionAction)($customer);
            $this->log(
                $customer->id,
                null,
                null,
                MurguiaSyncLog::ACTION_ALTA,
                [],
                ['result_code' => 'subscription_created'],
                MurguiaSyncLog::STATUS_SUCCESS,
                'murguia.activation.subscription_created'
            );

            return;
        }

        $ok = ($this->syncSubscriptionToMurguiaAction)(
            $subscription,
            'activo',
            Carbon::parse($subscription->start_date),
            Carbon::parse($subscription->end_date)
        );

        $this->log(
            $customer->id,
            null,
            null,
            MurguiaSyncLog::ACTION_ALTA,
            [],
            ['synced' => $ok],
            $ok ? MurguiaSyncLog::STATUS_SUCCESS : MurguiaSyncLog::STATUS_FAILED,
            'murguia.activation.sync_completed'
        );
    }

    /**
     * @param  array<string, mixed>  $responsePayload
     */
    private function log(
        ?int $customerId,
        ?string $email,
        ?string $identifier,
        string $action,
        array $requestPayload,
        ?array $responsePayload,
        string $status,
        string $message,
        ?int $triggeredBy = null,
        string $entryType = MurguiaSyncLog::ENTRY_TYPE_BULK
    ): void {
        MurguiaSyncLog::create([
            'customer_id' => $customerId,
            'triggered_by' => $triggeredBy,
            'email' => null,
            'medical_attention_identifier' => null,
            'action' => $action,
            'request_payload' => null,
            'response_payload' => $this->sanitizedResponsePayload($responsePayload),
            'status' => $status,
            'message' => $this->sanitizeEventCode($message),
            'entry_type' => $entryType,
        ]);
    }

    private function sanitizedResponsePayload(?array $payload): ?array
    {
        if (! $payload) {
            return null;
        }

        return array_filter([
            'http_status' => isset($payload['http_status']) ? (int) $payload['http_status'] : null,
            'result_code' => isset($payload['result_code']) ? $this->sanitizeEventCode((string) $payload['result_code']) : null,
            'error_code' => isset($payload['exception_type']) ? class_basename((string) $payload['exception_type']) : null,
            'synced' => array_key_exists('synced', $payload) ? (bool) $payload['synced'] : null,
        ], fn ($value) => $value !== null);
    }

    private function sanitizeEventCode(string $value): string
    {
        $code = Str::of($value)->lower()->replaceMatches('/[^a-z0-9_.-]+/', '_')->trim('_')->toString();

        return $code !== '' ? mb_substr($code, 0, 120) : 'murguia.event';
    }

    private function interpretCheckStatus(array $body): string
    {
        if (isset($body['success']) && $body['success'] === true) {
            return 'registrado_en_murguia';
        }

        if (isset($body['estatus'])) {
            return 'status_present';
        }

        return 'no_registrado_o_respuesta_no_estandar';
    }
}
