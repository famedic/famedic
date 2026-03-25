<?php

namespace App\Actions\Admin;

use App\Actions\MedicalAttention\CheckStatusAction;
use App\Actions\MedicalAttention\CreateInstitutionalOdessaSubscriptionAction;
use App\Actions\MedicalAttention\UpdateStatusAction;
use App\Enums\MedicalSubscriptionType;
use App\Models\Customer;
use App\Models\MurguiaSyncLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alta / baja individual desde el monitor (auditoría + cola).
 */
class MurguiaMonitorSingleCustomerAction
{
    public function __construct(
        private CheckStatusAction $checkStatusAction,
        private UpdateStatusAction $updateStatusAction,
        private CreateInstitutionalOdessaSubscriptionAction $createInstitutionalOdessaSubscriptionAction,
    ) {}

    public function __invoke(
        int $customerId,
        string $action,
        ?int $triggeredByUserId
    ): array {
        $action = strtolower($action);

        if (! in_array($action, ['activate', 'deactivate'], true)) {
            return ['ok' => false, 'message' => 'Acción inválida.'];
        }

        $customer = Customer::query()->with(['user', 'customerable', 'medicalAttentionSubscriptions'])->find($customerId);

        if (! $customer) {
            return ['ok' => false, 'message' => 'Cliente no encontrado.'];
        }

        if ($customer->medical_attention_identifier === null || $customer->medical_attention_identifier === '') {
            $this->writeLog(
                $customer,
                $action === 'activate' ? MurguiaSyncLog::ACTION_ALTA : MurguiaSyncLog::ACTION_BAJA,
                [],
                null,
                MurguiaSyncLog::STATUS_FAILED,
                'Falta medical_attention_identifier (noCredito).',
                $triggeredByUserId
            );

            return ['ok' => false, 'message' => 'El cliente no tiene número de crédito (noCredito).'];
        }

        try {
            return $action === 'activate'
                ? $this->activate($customer, $triggeredByUserId)
                : $this->deactivate($customer, $triggeredByUserId);
        } catch (Throwable $e) {
            Log::error('MurguiaMonitorSingleCustomerAction failed', [
                'customer_id' => $customerId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Error interno: ' . $e->getMessage()];
        }
    }

    private function activate(Customer $customer, ?int $triggeredByUserId): array
    {
        if (! $customer->isEligibleForInstitutionalOdessaLicense()) {
            $this->writeLog(
                $customer,
                MurguiaSyncLog::ACTION_ALTA,
                [
                    'note' => 'no_elegible_licencia_institucional',
                    'customerable_type' => $customer->customerable_type,
                ],
                null,
                MurguiaSyncLog::STATUS_FAILED,
                'Cliente no elegible para licencia institucional Odessa (tipo: ' . $customer->murguiaAdminAccountTypeLabel() . ').',
                $triggeredByUserId
            );

            return [
                'ok' => false,
                'message' => 'No se puede activar licencia institucional: el cliente está registrado como «'
                    . $customer->murguiaAdminAccountTypeLabel()
                    . '». Solo aplica a afiliados Odessa o certificados bajo empresa Odessa. '
                    . 'Si en Famedic debería ser Odessa, corrija el tipo de cuenta del cliente; en entorno local puede usar MURGUIA_INSTITUTIONAL_ALLOW_NON_ODESSA=true con precaución.',
            ];
        }

        if ($this->hasActiveInstitutionalSubscription($customer)) {
            $this->writeLog(
                $customer,
                MurguiaSyncLog::ACTION_ALTA,
                ['note' => 'ya_institucional_activa'],
                null,
                MurguiaSyncLog::STATUS_FAILED,
                'El cliente ya tiene suscripción institucional Odessa vigente.',
                $triggeredByUserId
            );

            return ['ok' => false, 'message' => 'El cliente ya tiene licencia institucional Odessa activa.'];
        }

        return DB::transaction(function () use ($customer, $triggeredByUserId) {
            $endedIds = $this->endActiveNonInstitutionalSubscriptions($customer);

            $subscription = ($this->createInstitutionalOdessaSubscriptionAction)($customer);

            $this->writeLog(
                $customer,
                MurguiaSyncLog::ACTION_ALTA,
                [
                    'created_subscription_id' => $subscription->id,
                    'type' => 'institutional_odessa',
                    'ended_subscription_ids' => $endedIds,
                ],
                ['note' => 'sync_ejecutado_en_misma_petición'],
                MurguiaSyncLog::STATUS_SUCCESS,
                empty($endedIds)
                    ? 'Alta institucional Odessa: suscripción creada y sincronización con Murguía completada.'
                    : 'Upgrade a institucional Odessa: suscripciones anteriores cerradas, nueva suscripción creada y Murguía sincronizado.',
                $triggeredByUserId
            );

            return [
                'ok' => true,
                'message' => empty($endedIds)
                    ? 'Licencia institucional creada y sincronizada con Murguía.'
                    : 'Se cerró la suscripción anterior y se creó la licencia institucional Odessa (sincronizada con Murguía).',
            ];
        });
    }

    private function deactivate(Customer $customer, ?int $triggeredByUserId): array
    {
        $check = ($this->checkStatusAction)($customer);
        $body = $check->json() ?? [];
        $existsInMurguia = isset($body['success']) && $body['success'] === true;

        if (! $check->successful() || ! $existsInMurguia) {
            $this->writeLog(
                $customer,
                MurguiaSyncLog::ACTION_BAJA,
                ['consultar_estatus' => $body],
                array_merge($body, ['http_status' => $check->status()]),
                MurguiaSyncLog::STATUS_FAILED,
                'No se pudo desactivar: el asegurado no aparece registrado en Murguía (consultar estatus).',
                $triggeredByUserId
            );

            return ['ok' => false, 'message' => 'No consta en Murguía o la consulta falló; no se envió baja.'];
        }

        $response = ($this->updateStatusAction)($customer, 'inactivo');
        $respBody = $response->json() ?? [];

        $payload = [
            'noCredito' => $customer->medical_attention_identifier,
            'estatus' => 'inactivo',
        ];

        $ok = $response->successful();

        $this->writeLog(
            $customer,
            MurguiaSyncLog::ACTION_BAJA,
            $payload,
            array_merge($respBody, ['http_status' => $response->status()]),
            $ok ? MurguiaSyncLog::STATUS_SUCCESS : MurguiaSyncLog::STATUS_FAILED,
            $ok
                ? 'Baja en Murguía (inactivo) enviada correctamente. Suscripción local no modificada.'
                : 'Fallo al enviar baja a Murguía (HTTP ' . $response->status() . ').',
            $triggeredByUserId
        );

        return [
            'ok' => $ok,
            'message' => $ok
                ? 'Desactivación enviada a Murguía (estatus inactivo).'
                : 'Murguía rechazó o falló la actualización: HTTP ' . $response->status() . ' — ' . $this->shortMurguiaErrorMessage($response),
        ];
    }

    private function shortMurguiaErrorMessage(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            if (isset($json['message']) && is_string($json['message'])) {
                return mb_substr($json['message'], 0, 280);
            }
            if (isset($json['error']) && is_string($json['error'])) {
                return mb_substr($json['error'], 0, 280);
            }
            if (isset($json['mensaje']) && is_string($json['mensaje'])) {
                return mb_substr($json['mensaje'], 0, 280);
            }

            return mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE), 0, 280);
        }

        $raw = $response->body();

        return mb_substr($raw !== '' ? $raw : '(sin cuerpo)', 0, 280);
    }

    /**
     * Suscripción institucional vigente (no se duplica).
     */
    private function hasActiveInstitutionalSubscription(Customer $customer): bool
    {
        return $customer->medicalAttentionSubscriptions()
            ->where('type', MedicalSubscriptionType::INSTITUTIONAL)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date', '>=', now()->toDateString())
            ->exists();
    }

    /**
     * Cierra vigencias activas que no son institucionales (upgrade regular/trial/familiar → institucional).
     *
     * @return list<int> IDs de suscripciones cerradas
     */
    private function endActiveNonInstitutionalSubscriptions(Customer $customer): array
    {
        $subs = $customer->medicalAttentionSubscriptions()
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date', '>=', now()->toDateString())
            ->where('type', '!=', MedicalSubscriptionType::INSTITUTIONAL)
            ->get();

        $ended = [];
        $cutoff = now()->subDay()->toDateString();

        foreach ($subs as $sub) {
            $sub->update(['end_date' => $cutoff]);
            $ended[] = $sub->id;
        }

        return $ended;
    }

    private function writeLog(
        Customer $customer,
        string $action,
        array $requestPayload,
        ?array $responsePayload,
        string $status,
        string $message,
        ?int $triggeredByUserId
    ): void {
        MurguiaSyncLog::create([
            'customer_id' => $customer->id,
            'triggered_by' => $triggeredByUserId,
            'email' => $customer->user?->email,
            'medical_attention_identifier' => $customer->medical_attention_identifier,
            'action' => $action,
            'request_payload' => $requestPayload ?: null,
            'response_payload' => $responsePayload,
            'status' => $status,
            'message' => $message,
            'entry_type' => MurguiaSyncLog::ENTRY_TYPE_SINGLE,
        ]);
    }
}
