<?php

namespace App\Services\Murguia;

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
use Throwable;

class MurguiaInsuredExcelRowProcessor
{
    public function __construct(
        private CheckStatusAction $checkStatusAction,
        private UpdateStatusAction $updateStatusAction,
        private CreateRegularSubscriptionAction $createRegularSubscriptionAction,
        private SyncSubscriptionToMurguiaAction $syncSubscriptionToMurguiaAction,
        private CreateUserAction $createUserAction,
        private CreateRegularAccountCustomerAction $createRegularAccountCustomerAction
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

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->log(
                null,
                $email ?: null,
                $identifier,
                $accion ?: 'unknown',
                [],
                null,
                MurguiaSyncLog::STATUS_FAILED,
                "Fila {$rowNumber}: email inválido o vacío."
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
                $email,
                $identifier,
                $accion ?: 'unknown',
                [],
                null,
                MurguiaSyncLog::STATUS_FAILED,
                "Fila {$rowNumber}: acción no reconocida (use alta, baja o validacion)."
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
                'row' => $rowNumber,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            $this->log(
                null,
                $email,
                $identifier,
                $accion,
                $row,
                ['exception' => $e->getMessage()],
                MurguiaSyncLog::STATUS_FAILED,
                $e->getMessage()
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
                $email,
                $identifier,
                MurguiaSyncLog::ACTION_VALIDACION,
                ['noCredito' => $identifier],
                null,
                MurguiaSyncLog::STATUS_NOT_FOUND,
                "Fila {$rowNumber}: usuario no encontrado."
            );

            return;
        }

        $requestPayload = ['noCredito' => (string) $customer->medical_attention_identifier];

        $response = ($this->checkStatusAction)($customer);
        $body = $response->json() ?? [];

        $interpretation = $this->interpretCheckStatus($body);

        $this->log(
            $customer->id,
            $email,
            $identifier,
            MurguiaSyncLog::ACTION_VALIDACION,
            $requestPayload,
            array_merge($body, ['_interpretation' => $interpretation]),
            $response->successful() ? MurguiaSyncLog::STATUS_SUCCESS : MurguiaSyncLog::STATUS_FAILED,
            "Fila {$rowNumber}: {$interpretation} (HTTP {$response->status()})"
        );
    }

    private function runBaja(string $email, ?string $identifier, int $rowNumber): void
    {
        $customer = $this->findCustomer($email, $identifier);

        if (! $customer) {
            $this->log(
                null,
                $email,
                $identifier,
                MurguiaSyncLog::ACTION_BAJA,
                [],
                null,
                MurguiaSyncLog::STATUS_NOT_FOUND,
                "Fila {$rowNumber}: usuario no encontrado."
            );

            return;
        }

        $payload = [
            'noCredito' => $customer->medical_attention_identifier,
            'estatus' => 'inactivo',
        ];

        $response = ($this->updateStatusAction)($customer, 'inactivo');
        $body = $response->json() ?? [];

        $this->log(
            $customer->id,
            $email,
            $identifier,
            MurguiaSyncLog::ACTION_BAJA,
            $payload,
            $body,
            $response->successful() ? MurguiaSyncLog::STATUS_SUCCESS : MurguiaSyncLog::STATUS_FAILED,
            "Fila {$rowNumber}: baja Murguía " . ($response->successful() ? 'OK' : 'falló') . " (HTTP {$response->status()})"
        );
    }

    private function runAlta(string $email, ?string $identifier, int $rowNumber): void
    {
        $customer = $this->findCustomer($email, $identifier);

        if (! $customer) {
            if ($identifier && Customer::where('medical_attention_identifier', $identifier)->exists()) {
                $this->log(
                    null,
                    $email,
                    $identifier,
                    MurguiaSyncLog::ACTION_ALTA,
                    [],
                    null,
                    MurguiaSyncLog::STATUS_FAILED,
                    "Fila {$rowNumber}: medical_attention_identifier ya existe en otro cliente."
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
                    $email,
                    $identifier,
                    MurguiaSyncLog::ACTION_ALTA,
                    [],
                    null,
                    MurguiaSyncLog::STATUS_FAILED,
                    "Fila {$rowNumber}: no se pudo crear u obtener el cliente."
                );

                return;
            }

            if ($identifier) {
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
                $email,
                $identifier,
                MurguiaSyncLog::ACTION_ALTA,
                ['created_subscription_id' => $subscription->id],
                ['note' => 'Suscripción creada; sincronización despachada a cola.'],
                MurguiaSyncLog::STATUS_SUCCESS,
                "Fila {$rowNumber}: alta — suscripción creada y job de sync Murguía despachado."
            );

            return;
        }

        if ($subscription->type === MedicalSubscriptionType::TRIAL) {
            $label = 'trial';
        } elseif ($subscription->type === MedicalSubscriptionType::REGULAR) {
            $label = 'regular';
        } else {
            $label = (string) $subscription->type->value;
        }

        $ok = ($this->syncSubscriptionToMurguiaAction)(
            $subscription,
            'activo',
            Carbon::parse($subscription->start_date),
            Carbon::parse($subscription->end_date)
        );

        $this->log(
            $customer->id,
            $email,
            $identifier,
            MurguiaSyncLog::ACTION_ALTA,
            ['subscription_id' => $subscription->id, 'subscription_type' => $label],
            ['synced' => $ok],
            $ok ? MurguiaSyncLog::STATUS_SUCCESS : MurguiaSyncLog::STATUS_FAILED,
            "Fila {$rowNumber}: alta — sync Murguía " . ($ok ? 'OK' : 'falló') . " (suscripción existente)."
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
            'email' => $email,
            'medical_attention_identifier' => $identifier,
            'action' => $action,
            'request_payload' => $requestPayload ?: null,
            'response_payload' => $responsePayload,
            'status' => $status,
            'message' => $message,
            'entry_type' => $entryType,
        ]);
    }

    private function interpretCheckStatus(array $body): string
    {
        if (isset($body['success']) && $body['success'] === true) {
            return 'registrado_en_murguia';
        }

        if (isset($body['estatus'])) {
            return 'estatus: ' . $body['estatus'];
        }

        return 'no_registrado_o_respuesta_no_estandar';
    }
}
