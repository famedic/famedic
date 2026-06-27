<?php

namespace App\Services\Laboratory;

use App\Actions\Laboratory\CreateNotificationAction;
use App\Actions\Laboratory\ProcessNotificationAction;
use App\Models\LabOrderEventState;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryQuote;
use App\Notifications\LaboratoryResultsAvailable;
use App\Notifications\LaboratorySampleCollected;
use App\Support\Laboratory\GdaSimulatorSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GdaNotificationSimulatorService
{
    public function __construct(
        protected CreateNotificationAction $createNotificationAction,
        protected ProcessNotificationAction $processNotificationAction,
    ) {}

    /**
     * @return array{
     *     notifications: list<array<string, mixed>>,
     *     gate_state: array<string, mixed>|null,
     *     purchase: array<string, mixed>,
     *     can_resend_sample: bool,
     *     can_resend_results: bool,
     * }
     */
    public function historyForPurchase(LaboratoryPurchase $purchase): array
    {
        $purchase->loadMissing(['customer.user', 'laboratoryPurchaseItems']);

        $gdaOrderId = $this->resolveGdaOrderId($purchase);

        $notifications = LaboratoryNotification::query()
            ->where(function ($query) use ($purchase, $gdaOrderId) {
                $query->where('laboratory_purchase_id', $purchase->id);
                if ($gdaOrderId !== '') {
                    $query->orWhere('gda_order_id', $gdaOrderId);
                }
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $user = $purchase->customer?->user;
        $hasSample = $notifications->contains(fn (LaboratoryNotification $n) => $this->isSampleType($n));
        $hasResults = $notifications->contains(fn (LaboratoryNotification $n) => $this->isResultsType($n));

        $gateState = $gdaOrderId !== ''
            ? LabOrderEventState::query()->where('gda_order_id', $gdaOrderId)->first()
            : null;

        return [
            'purchase' => $this->purchaseSummary($purchase, $gdaOrderId),
            'notifications' => $notifications->map(fn (LaboratoryNotification $n) => $this->formatNotification($n))->values()->all(),
            'gate_state' => $gateState ? $this->formatGateState($gateState) : null,
            'can_resend_sample' => $hasSample && filled($user?->email),
            'can_resend_results' => $hasResults && filled($user?->email),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function simulate(
        LaboratoryPurchase $purchase,
        string $notificationType,
        bool $sendEmail,
        ?int $purchaseItemId = null
    ): array {
        $purchase->loadMissing(['customer.user', 'laboratoryPurchaseItems']);

        $gdaOrderId = $this->resolveGdaOrderId($purchase);
        if ($gdaOrderId === '') {
            throw ValidationException::withMessages([
                'laboratory_purchase' => 'El pedido no tiene gda_order_id ni gda_consecutivo para simular el webhook.',
            ]);
        }

        $item = $this->resolvePurchaseItem($purchase, $purchaseItemId);
        $payload = $this->buildPayload($purchase, $gdaOrderId, $notificationType, $item);
        $references = $this->referencesFromPurchase($purchase);

        $httpRequest = Request::create('/api/laboratory/webhook/notifications', 'POST', $payload);

        app()->instance(GdaSimulatorSettings::class, new GdaSimulatorSettings(
            sendEmail: $sendEmail,
            bypassGate: $sendEmail,
        ));

        try {
            return DB::transaction(function () use ($purchase, $payload, $httpRequest, $references, $gdaOrderId, $sendEmail) {
                $notification = $this->createNotificationAction->execute($payload, $httpRequest, $references);

                $this->processNotificationAction->execute($notification, $payload, $references);

                $notification->refresh();
                $gateState = LabOrderEventState::query()->where('gda_order_id', $gdaOrderId)->first();

                return [
                    'success' => true,
                    'message' => $sendEmail
                        ? 'Notificación simulada y procesada (correo según reglas de envío / gate).'
                        : 'Notificación simulada y registrada sin envío de correo.',
                    'notification' => $this->formatNotification($notification),
                    'gate_state' => $gateState ? $this->formatGateState($gateState) : null,
                    'payload_preview' => [
                        'header.lineanegocio' => $payload['header']['lineanegocio'],
                        'id' => $payload['id'],
                        'status' => $payload['status'],
                        'study' => $payload['code']['coding'][0]['display'] ?? null,
                    ],
                ];
            });
        } finally {
            app()->forgetInstance(GdaSimulatorSettings::class);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function resendEmail(LaboratoryPurchase $purchase, string $type): array
    {
        $purchase->loadMissing(['customer.user']);
        $quote = LaboratoryQuote::query()->where('laboratory_purchase_id', $purchase->id)->first();

        $user = $purchase->customer?->user;
        if (! $user?->email) {
            throw ValidationException::withMessages([
                'laboratory_purchase' => 'El cliente de este pedido no tiene correo para reenviar.',
            ]);
        }

        $gdaOrderId = $this->resolveGdaOrderId($purchase);

        $notificationQuery = LaboratoryNotification::query()
            ->where(function ($query) use ($purchase, $gdaOrderId) {
                $query->where('laboratory_purchase_id', $purchase->id);
                if ($gdaOrderId !== '') {
                    $query->orWhere('gda_order_id', $gdaOrderId);
                }
            });

        if ($type === 'sample_collection') {
            $notification = (clone $notificationQuery)
                ->where(function ($q) {
                    $q->where('notification_type', LaboratoryNotification::TYPE_SAMPLE_COLLECTION)
                        ->orWhere('lineanegocio', LaboratoryNotification::LINEA_NEGOCIO_SAMPLE);
                })
                ->latest('id')
                ->first();

            if (! $notification) {
                throw ValidationException::withMessages([
                    'type' => 'No hay registros de toma de muestra para este pedido.',
                ]);
            }

            $user->notify(new LaboratorySampleCollected(
                laboratoryPurchase: $purchase,
                laboratoryQuote: $quote,
                gdaOrderId: $gdaOrderId ?: (string) $purchase->id
            ));

            $notification->update([
                'email_sent_at' => now(),
                'email_error' => null,
                'email_recipient_id' => $user->id,
                'email_recipient_email' => $user->email,
                'email_attempted_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Correo de toma de muestra reenviado a '.$user->email,
                'notification_id' => $notification->id,
            ];
        }

        if ($type === 'results') {
            $notification = (clone $notificationQuery)
                ->where(function ($q) {
                    $q->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
                        ->orWhere('lineanegocio', LaboratoryNotification::LINEA_NEGOCIO_RESULTS);
                })
                ->latest('id')
                ->first();

            if (! $notification) {
                throw ValidationException::withMessages([
                    'type' => 'No hay registros de resultados para este pedido.',
                ]);
            }

            $hasPdf = filled($notification->results_pdf_base64);

            $user->notify(new LaboratoryResultsAvailable(
                laboratoryPurchase: $purchase,
                laboratoryQuote: $quote,
                gdaOrderId: $gdaOrderId ?: (string) $purchase->id,
                hasPdfInPayload: $hasPdf
            ));

            $notification->update([
                'email_sent_at' => now(),
                'email_error' => null,
                'email_recipient_id' => $user->id,
                'email_recipient_email' => $user->email,
                'email_attempted_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Correo de resultados reenviado a '.$user->email,
                'notification_id' => $notification->id,
            ];
        }

        throw ValidationException::withMessages([
            'type' => 'Tipo de reenvío no válido.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(
        LaboratoryPurchase $purchase,
        string $gdaOrderId,
        string $notificationType,
        LaboratoryPurchaseItem $item
    ): array {
        $isSample = $notificationType === 'sample_collection';

        $brandKey = $purchase->brand?->value ?? 'swisslab';
        $brandConfig = config('services.gda.brands.'.$brandKey, []);
        $marca = (int) ($brandConfig['brand_id'] ?? 5);
        $convenio = (int) ($brandConfig['brand_agreement_id'] ?? 99999);

        $acuse = 'sim-'.Str::uuid()->toString();

        $payload = [
            'header' => [
                'lineanegocio' => $isSample
                    ? LaboratoryNotification::LINEA_NEGOCIO_SAMPLE
                    : LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
                'registro' => now()->format('Y-m-d\TH:i:s:000'),
                'marca' => $marca,
                'token' => 'simulator-token',
            ],
            'resourceType' => 'ServiceRequest',
            'id' => $gdaOrderId,
            'requisition' => [
                'system' => 'urn:oid:2.16.840.1.113883.3.215.5.59',
                'value' => (string) ($purchase->gda_order_id ?? $gdaOrderId),
                'convenio' => $convenio,
            ],
            'status' => $isSample ? 'in-progress' : 'completed',
            'intent' => 'order',
            'code' => [
                'coding' => [
                    [
                        'system' => 'urn:oid:2.16.840.1.113883.3.215.5.59',
                        'code' => (string) $item->gda_id,
                        'display' => (string) $item->name,
                    ],
                ],
            ],
            'subject' => [
                'reference' => 'Patient/'.($purchase->customer_id ?? 0),
            ],
            'requester' => [
                'reference' => 'Practitioner/simulator',
                'display' => 'SIMULADOR FAMEDIC',
            ],
            'GDA_menssage' => [
                'codeHttp' => 200,
                'mensaje' => 'success',
                'descripcion' => $isSample
                    ? 'Simulación admin: toma de muestra'
                    : 'Simulación admin: resultados disponibles',
                'acuse' => $acuse,
            ],
        ];

        if (! $isSample) {
            $payload['infogda_resultado_b64'] = base64_encode('%PDF-1.4 simulator placeholder');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function referencesFromPurchase(LaboratoryPurchase $purchase): array
    {
        $quoteId = LaboratoryQuote::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->value('id');

        return [
            'quote_id' => $quoteId,
            'purchase_id' => $purchase->id,
            'user_id' => $purchase->customer?->user_id,
            'contact_id' => null,
        ];
    }

    protected function resolveGdaOrderId(LaboratoryPurchase $purchase): string
    {
        if (filled($purchase->gda_order_id)) {
            return (string) $purchase->gda_order_id;
        }

        if (filled($purchase->gda_consecutivo)) {
            return (string) $purchase->gda_consecutivo;
        }

        return '';
    }

    protected function resolvePurchaseItem(LaboratoryPurchase $purchase, ?int $purchaseItemId): LaboratoryPurchaseItem
    {
        if ($purchaseItemId) {
            $item = $purchase->laboratoryPurchaseItems->firstWhere('id', $purchaseItemId);
            if ($item) {
                return $item;
            }
        }

        $item = $purchase->laboratoryPurchaseItems->first();
        if (! $item) {
            throw ValidationException::withMessages([
                'laboratory_purchase' => 'El pedido no tiene estudios (items) para armar el payload GDA.',
            ]);
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    protected function purchaseSummary(LaboratoryPurchase $purchase, string $gdaOrderId): array
    {
        $user = $purchase->customer?->user;

        return [
            'id' => $purchase->id,
            'gda_order_id' => $purchase->gda_order_id,
            'gda_consecutivo' => $purchase->gda_consecutivo,
            'gda_order_key' => $gdaOrderId,
            'brand' => $purchase->brand?->value,
            'brand_label' => $purchase->brand?->label() ?? '—',
            'studies_count' => $purchase->laboratoryPurchaseItems->count(),
            'customer_email' => $user?->email,
            'customer_label' => $user
                ? trim("{$user->name} {$user->paternal_lastname} {$user->maternal_lastname}")
                : null,
            'items' => $purchase->laboratoryPurchaseItems->map(fn (LaboratoryPurchaseItem $item) => [
                'id' => $item->id,
                'gda_id' => $item->gda_id,
                'name' => $item->name,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatNotification(LaboratoryNotification $notification): array
    {
        $typeLabel = match (true) {
            $this->isSampleType($notification) => 'Toma de muestra',
            $this->isResultsType($notification) => 'Resultados',
            default => $notification->notification_type ?? 'Otro',
        };

        return [
            'id' => $notification->id,
            'type' => $notification->notification_type,
            'type_label' => $typeLabel,
            'lineanegocio' => $notification->lineanegocio,
            'status' => $notification->status,
            'gda_status' => $notification->gda_status,
            'gda_order_id' => $notification->gda_order_id,
            'gda_acuse' => $notification->gda_acuse,
            'email_sent_at' => $notification->email_sent_at?->format('d/m/Y H:i'),
            'email_recipient_email' => $notification->email_recipient_email,
            'email_error' => $notification->email_error,
            'has_pdf' => filled($notification->results_pdf_base64),
            'created_at' => $notification->created_at?->format('d/m/Y H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatGateState(LabOrderEventState $state): array
    {
        $total = max(1, (int) $state->total_studies);

        return [
            'gda_order_id' => $state->gda_order_id,
            'total_studies' => $state->total_studies,
            'sample_received_count' => $state->sample_received_count,
            'results_received_count' => $state->results_received_count,
            'sample_email_sent_at' => $state->sample_email_sent_at?->format('d/m/Y H:i'),
            'results_email_sent_at' => $state->results_email_sent_at?->format('d/m/Y H:i'),
            'sample_ready' => $state->sample_received_count >= $total,
            'results_ready' => $state->results_received_count >= 1,
        ];
    }

    protected function isSampleType(LaboratoryNotification $notification): bool
    {
        return $notification->notification_type === LaboratoryNotification::TYPE_SAMPLE_COLLECTION
            || $notification->lineanegocio === LaboratoryNotification::LINEA_NEGOCIO_SAMPLE;
    }

    protected function isResultsType(LaboratoryNotification $notification): bool
    {
        return $notification->notification_type === LaboratoryNotification::TYPE_RESULTS
            || $notification->lineanegocio === LaboratoryNotification::LINEA_NEGOCIO_RESULTS;
    }
}
