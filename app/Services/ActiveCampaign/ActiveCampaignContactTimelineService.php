<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use App\Models\Contact;
use App\Models\CouponUser;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Historial cronológico del paciente (Famedic local).
 * Reutilizable por Drawer 360, Customer Journey y Event Center.
 * El rango de fechas se aplica en las consultas (no post-filtro sobre LIMIT).
 */
class ActiveCampaignContactTimelineService
{
    private const TZ = 'America/Monterrey';

    private const LIMIT = 50;

    private const PER_SOURCE_DEFAULT = 20;

    /**
     * @return array{
     *     events: list<array<string, mixed>>,
     *     upcoming: list<array<string, string>>,
     *     meta: array<string, mixed>
     * }
     */
    public function buildForContact(
        Contact $contact,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): array {
        $contact->loadMissing(['customer.user:id,email']);

        $customer = $contact->customer;
        $perSource = ($start || $end) ? self::LIMIT : self::PER_SOURCE_DEFAULT;
        $events = collect();

        if ($this->inRange($contact->created_at, $start, $end)) {
            $events->push($this->event(
                id: 'contact-registered-'.$contact->id,
                at: $contact->created_at,
                type: 'registration',
                typeLabel: 'Registro',
                source: 'famedic',
                sourceLabel: 'Famedic',
                description: 'Paciente registrado en Famedic.',
                status: 'completed',
                statusLabel: 'Completado',
                badge: 'Registro',
                icon: 'user',
                color: 'sky',
            ));
        }

        if ($customer) {
            $events = $events
                ->merge($this->laboratoryPurchaseEvents($customer, $start, $end, $perSource))
                ->merge($this->laboratoryResultEvents($customer, $start, $end, $perSource))
                ->merge($this->pharmacyPurchaseEvents($customer, $start, $end, $perSource))
                ->merge($this->invoiceEvents($customer, $start, $end, $perSource))
                ->merge($this->membershipEvents($customer, $start, $end, $perSource))
                ->merge($this->familyBeneficiaryEvents($customer, $start, $end, $perSource))
                ->merge($this->couponAssignedEvents($customer, $start, $end, $perSource))
                ->merge($this->dispatchEvents($customer, $start, $end, $perSource));
        }

        $sorted = $events
            ->filter()
            ->sortByDesc(fn (array $event) => $event['occurred_at_sort'])
            ->values()
            ->take(self::LIMIT)
            ->map(function (array $event) {
                unset($event['occurred_at_sort']);

                return $event;
            })
            ->all();

        return [
            'events' => $sorted,
            'upcoming' => [
                [
                    'type' => 'email_open',
                    'label' => 'Apertura de email',
                    'reason' => 'Requiere ActiveCampaign API',
                ],
                [
                    'type' => 'email_click',
                    'label' => 'Click en campaña',
                    'reason' => 'Requiere ActiveCampaign API',
                ],
                [
                    'type' => 'journey_stage',
                    'label' => 'Cambio de etapa Journey',
                    'reason' => 'Próximamente',
                ],
            ],
            'meta' => [
                'contact_id' => $contact->id,
                'customer_id' => $customer?->id,
                'limit' => self::LIMIT,
                'start' => $start?->toIso8601String(),
                'end' => $end?->toIso8601String(),
                'reusable_for' => ['drawer_360', 'customer_journey', 'event_center'],
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function laboratoryPurchaseEvents(
        Customer $customer,
        ?Carbon $start,
        ?Carbon $end,
        int $limit,
    ): Collection {
        $query = LaboratoryPurchase::query()
            ->withTrashed()
            ->where('customer_id', $customer->id);

        $this->constrainDate($query, 'created_at', $start, $end);

        return $query
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'brand', 'created_at', 'total_cents', 'deleted_at'])
            ->map(function (LaboratoryPurchase $purchase) {
                $brand = $purchase->brand?->label() ?: 'Laboratorio';
                $amount = $this->money($purchase->total_cents);
                $trashed = $purchase->trashed();

                return $this->event(
                    id: 'lab-purchase-'.$purchase->id,
                    at: $purchase->created_at,
                    type: 'laboratory_purchase',
                    typeLabel: 'Compra laboratorio',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Laboratorio',
                    description: "Compra {$brand}".($amount ? " por {$amount}" : '').'.',
                    status: $trashed ? 'cancelled' : 'completed',
                    statusLabel: $trashed ? 'Cancelada' : 'Completada',
                    badge: 'Lab',
                    icon: 'beaker',
                    color: $trashed ? 'zinc' : 'blue',
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function laboratoryResultEvents(
        Customer $customer,
        ?Carbon $start,
        ?Carbon $end,
        int $limit,
    ): Collection {
        $query = LaboratoryPurchase::query()
            ->where('customer_id', $customer->id)
            ->where(function ($q) {
                $q->whereNotNull('results')
                    ->orWhereNotNull('ready_at');
            });

        if ($start || $end) {
            $query->where(function ($q) use ($start, $end) {
                $q->where(function ($inner) use ($start, $end) {
                    $this->constrainDate($inner, 'ready_at', $start, $end);
                })->orWhere(function ($inner) use ($start, $end) {
                    $inner->whereNull('ready_at');
                    $this->constrainDate($inner, 'updated_at', $start, $end);
                });
            });
        }

        return $query
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'brand', 'results', 'ready_at', 'created_at', 'updated_at'])
            ->map(function (LaboratoryPurchase $purchase) {
                $at = $purchase->ready_at ?? $purchase->updated_at ?? $purchase->created_at;
                $brand = $purchase->brand?->label() ?: 'Laboratorio';

                return $this->event(
                    id: 'lab-results-'.$purchase->id,
                    at: $at,
                    type: 'laboratory_results',
                    typeLabel: 'Resultado laboratorio',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Laboratorio',
                    description: "Resultados disponibles ({$brand}).",
                    status: 'ready',
                    statusLabel: 'Disponible',
                    badge: 'Resultados',
                    icon: 'document',
                    color: 'emerald',
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pharmacyPurchaseEvents(
        Customer $customer,
        ?Carbon $start,
        ?Carbon $end,
        int $limit,
    ): Collection {
        $query = OnlinePharmacyPurchase::query()
            ->withTrashed()
            ->where('customer_id', $customer->id);

        $this->constrainDate($query, 'created_at', $start, $end);

        return $query
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'created_at', 'total_cents', 'deleted_at'])
            ->map(function (OnlinePharmacyPurchase $purchase) {
                $amount = $this->money($purchase->total_cents);
                $trashed = $purchase->trashed();

                return $this->event(
                    id: 'pharmacy-purchase-'.$purchase->id,
                    at: $purchase->created_at,
                    type: 'pharmacy_purchase',
                    typeLabel: 'Compra farmacia',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Farmacia',
                    description: 'Compra en farmacia en línea'.($amount ? " por {$amount}" : '').'.',
                    status: $trashed ? 'cancelled' : 'completed',
                    statusLabel: $trashed ? 'Cancelada' : 'Completada',
                    badge: 'Farmacia',
                    icon: 'building',
                    color: $trashed ? 'zinc' : 'purple',
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function invoiceEvents(
        Customer $customer,
        ?Carbon $start,
        ?Carbon $end,
        int $limit,
    ): Collection {
        $query = Invoice::query()
            ->whereHasMorph(
                'invoiceable',
                [LaboratoryPurchase::class, OnlinePharmacyPurchase::class],
                fn ($q) => $q->where('customer_id', $customer->id)
            );

        if ($start || $end) {
            $query->where(function ($q) use ($start, $end) {
                $q->where(function ($inner) use ($start, $end) {
                    $this->constrainDate($inner, 'completed_at', $start, $end);
                })->orWhere(function ($inner) use ($start, $end) {
                    $inner->whereNull('completed_at');
                    $this->constrainDate($inner, 'created_at', $start, $end);
                });
            });
        }

        return $query
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'created_at', 'completed_at', 'invoiceable_type'])
            ->map(function (Invoice $invoice) {
                $at = $invoice->completed_at ?? $invoice->created_at;
                $channel = str_contains((string) $invoice->invoiceable_type, 'Laboratory')
                    ? 'laboratorio'
                    : 'farmacia';

                return $this->event(
                    id: 'invoice-'.$invoice->id,
                    at: $at,
                    type: 'invoice',
                    typeLabel: 'Factura',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Facturación',
                    description: "Factura generada (compra de {$channel}).",
                    status: $invoice->completed_at ? 'completed' : 'pending',
                    statusLabel: $invoice->completed_at ? 'Completada' : 'En proceso',
                    badge: 'CFDI',
                    icon: 'receipt',
                    color: 'amber',
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function membershipEvents(
        Customer $customer,
        ?Carbon $start,
        ?Carbon $end,
        int $limit,
    ): Collection {
        $query = MedicalAttentionSubscription::query()
            ->where('customer_id', $customer->id);

        $this->constrainDate($query, 'created_at', $start, $end);

        return $query
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'start_date', 'end_date', 'created_at', 'type', 'price_cents'])
            ->map(function (MedicalAttentionSubscription $subscription) {
                $at = $subscription->start_date
                    ? Carbon::parse($subscription->start_date)->timezone(self::TZ)->startOfDay()
                    : $subscription->created_at;
                $active = $subscription->is_active;
                $type = $subscription->type?->label() ?? 'Membresía';
                $endLabel = $subscription->end_date
                    ? Carbon::parse($subscription->end_date)->timezone(self::TZ)->format('d/m/Y')
                    : null;

                return $this->event(
                    id: 'membership-'.$subscription->id,
                    at: $at,
                    type: 'membership',
                    typeLabel: 'Membresía',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Membresía',
                    description: "{$type}".($endLabel ? " · vigencia hasta {$endLabel}" : '').'.',
                    status: $active ? 'active' : 'inactive',
                    statusLabel: $active ? 'Activa' : 'Inactiva',
                    badge: 'Membresía',
                    icon: 'heart',
                    color: $active ? 'emerald' : 'zinc',
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function familyBeneficiaryEvents(
        Customer $customer,
        ?Carbon $start,
        ?Carbon $end,
        int $limit,
    ): Collection {
        $query = FamilyAccount::query()
            ->where('customer_id', $customer->id);

        $this->constrainDate($query, 'created_at', $start, $end);

        return $query
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'name', 'paternal_lastname', 'maternal_lastname', 'created_at', 'kinship'])
            ->map(function (FamilyAccount $member) {
                $name = trim((string) $member->full_name) ?: 'Familiar';
                $kinship = $member->formatted_kinship ?? null;

                return $this->event(
                    id: 'family-'.$member->id,
                    at: $member->created_at,
                    type: 'beneficiary_added',
                    typeLabel: 'Beneficiario agregado',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Familia',
                    description: "Se agregó a {$name}".($kinship ? " ({$kinship})" : '').'.',
                    status: 'completed',
                    statusLabel: 'Agregado',
                    badge: 'Familiar',
                    icon: 'users',
                    color: 'sky',
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function couponAssignedEvents(
        Customer $customer,
        ?Carbon $start,
        ?Carbon $end,
        int $limit,
    ): Collection {
        if (! $customer->user_id) {
            return collect();
        }

        $query = CouponUser::query()
            ->with('coupon:id,code')
            ->where('user_id', $customer->user_id)
            ->whereNotNull('assigned_at');

        $this->constrainDate($query, 'assigned_at', $start, $end);

        return $query
            ->latest('assigned_at')
            ->limit($limit)
            ->get()
            ->map(function (CouponUser $row) {
                $code = $row->coupon?->code ?: 'cupón';

                return $this->event(
                    id: 'coupon-assigned-'.$row->coupon_id.'-'.$row->user_id,
                    at: $row->assigned_at,
                    type: 'coupon_assigned',
                    typeLabel: 'Cupón asignado',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Cupones',
                    description: "Cupón {$code} asignado al usuario.",
                    status: $row->used_at ? 'used' : 'assigned',
                    statusLabel: $row->used_at ? 'Usado' : 'Asignado',
                    badge: 'Cupón',
                    icon: 'tag',
                    color: 'orange',
                );
            });
    }

    /**
     * Dispatches locales (tabla Famedic). No consulta la API de ActiveCampaign.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function dispatchEvents(
        Customer $customer,
        ?Carbon $start,
        ?Carbon $end,
        int $limit,
    ): Collection {
        $query = ActiveCampaignDispatch::query()
            ->where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id);
                if ($customer->user_id) {
                    $q->orWhere('user_id', $customer->user_id);
                }
                $email = $customer->user?->email;
                if (filled($email)) {
                    $q->orWhere('email', $email);
                }
            });

        if ($start || $end) {
            $query->where(function ($q) use ($start, $end) {
                $q->where(function ($inner) use ($start, $end) {
                    $this->constrainDate($inner, 'synced_at', $start, $end);
                })->orWhere(function ($inner) use ($start, $end) {
                    $inner->whereNull('synced_at');
                    $this->constrainDate($inner, 'created_at', $start, $end);
                });
            });
        }

        return $query
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'event_type', 'status', 'created_at', 'synced_at', 'email'])
            ->map(function (ActiveCampaignDispatch $dispatch) {
                $at = $dispatch->synced_at ?? $dispatch->created_at;
                $statusLabel = match ($dispatch->status) {
                    ActiveCampaignDispatch::STATUS_SYNCED => 'Sincronizado',
                    ActiveCampaignDispatch::STATUS_FAILED => 'Error',
                    ActiveCampaignDispatch::STATUS_PENDING => 'Pendiente',
                    ActiveCampaignDispatch::STATUS_PROCESSING => 'Procesando',
                    ActiveCampaignDispatch::STATUS_SKIPPED => 'Omitido',
                    default => (string) $dispatch->status,
                };
                $color = match ($dispatch->status) {
                    ActiveCampaignDispatch::STATUS_SYNCED => 'emerald',
                    ActiveCampaignDispatch::STATUS_FAILED => 'red',
                    ActiveCampaignDispatch::STATUS_PENDING,
                    ActiveCampaignDispatch::STATUS_PROCESSING => 'amber',
                    default => 'zinc',
                };

                return $this->event(
                    id: 'ac-dispatch-'.$dispatch->id,
                    at: $at,
                    type: 'activecampaign_dispatch',
                    typeLabel: 'Dispatch ActiveCampaign',
                    source: 'activecampaign_local',
                    sourceLabel: 'Famedic · Dispatches',
                    description: 'Evento '.$dispatch->event_type.'.',
                    status: (string) $dispatch->status,
                    statusLabel: $statusLabel,
                    badge: 'AC',
                    icon: 'bolt',
                    color: $color,
                );
            });
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder  $query
     */
    private function constrainDate($query, string $column, ?Carbon $start, ?Carbon $end): void
    {
        if ($start && $end) {
            $query->whereBetween($column, [$start, $end]);

            return;
        }

        if ($start) {
            $query->where($column, '>=', $start);
        }

        if ($end) {
            $query->where($column, '<=', $end);
        }
    }

    private function inRange(mixed $at, ?Carbon $start, ?Carbon $end): bool
    {
        if (! $at) {
            return false;
        }

        if (! $start && ! $end) {
            return true;
        }

        $carbon = $at instanceof Carbon ? $at->copy() : Carbon::parse($at);

        if ($start && $carbon->lt($start)) {
            return false;
        }

        if ($end && $carbon->gt($end)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function event(
        string $id,
        mixed $at,
        string $type,
        string $typeLabel,
        string $source,
        string $sourceLabel,
        string $description,
        string $status,
        string $statusLabel,
        string $badge,
        string $icon,
        string $color,
    ): ?array {
        if (! $at) {
            return null;
        }

        $carbon = $at instanceof Carbon ? $at->copy() : Carbon::parse($at);
        $local = $carbon->timezone(self::TZ);

        return [
            'id' => $id,
            'occurred_at' => $local->toIso8601String(),
            'occurred_at_sort' => $local->timestamp,
            'date' => $local->format('d/m/Y'),
            'time' => $local->format('H:i'),
            'type' => $type,
            'type_label' => $typeLabel,
            'source' => $source,
            'source_label' => $sourceLabel,
            'description' => $description,
            'status' => $status,
            'status_label' => $statusLabel,
            'badge' => $badge,
            'icon' => $icon,
            'color' => $color,
        ];
    }

    private function money(?int $cents): ?string
    {
        if ($cents === null) {
            return null;
        }

        if (function_exists('formattedCentsPrice')) {
            return formattedCentsPrice($cents);
        }

        return '$'.number_format($cents / 100, 2);
    }
}
