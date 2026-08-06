<?php

namespace App\Services\ActiveCampaign;

use App\Models\Contact;
use App\Models\CouponUser;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Secciones lazy del Drawer 360 (Famedic local).
 * Cada método es independiente y reutilizable (Journey / Analytics / Health).
 */
class ActiveCampaignContactDrawerSectionsService
{
    private const UNAVAILABLE = 'No disponible';

    private const TZ = 'America/Monterrey';

    private const LIMIT = 20;

    /** @var list<string> */
    public const SECTIONS = [
        'purchases',
        'laboratories',
        'memberships',
        'invoices',
        'coupons',
        'beneficiaries',
    ];

    /**
     * @return array{contact_id: int, section: string, items: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    public function build(int $contactId, string $section): ?array
    {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new InvalidArgumentException("Sección de drawer no soportada: {$section}");
        }

        $contact = Contact::query()
            ->with(['customer.user:id,email'])
            ->find($contactId);

        if (! $contact) {
            return null;
        }

        $customer = $contact->customer;
        $items = match ($section) {
            'purchases' => $this->purchases($customer),
            'laboratories' => $this->laboratories($customer),
            'memberships' => $this->memberships($customer),
            'invoices' => $this->invoices($customer),
            'coupons' => $this->coupons($customer),
            'beneficiaries' => $this->beneficiaries($customer),
        };

        return [
            'contact_id' => $contact->id,
            'section' => $section,
            'items' => $items,
            'meta' => [
                'limit' => self::LIMIT,
                'reusable_for' => ['drawer_360', 'customer_journey', 'analytics', 'health_center', 'ai'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function purchases(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        $lab = LaboratoryPurchase::query()
            ->withTrashed()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'brand', 'created_at', 'total_cents', 'completed_at', 'deleted_at']);

        $pharmacy = OnlinePharmacyPurchase::query()
            ->withTrashed()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'created_at', 'total_cents', 'deleted_at']);

        $rows = collect();

        foreach ($lab as $purchase) {
            $rows->push([
                'id' => 'lab-'.$purchase->id,
                'date' => $this->formatDate($purchase->created_at),
                'type' => 'Laboratorio',
                'amount' => $this->money($purchase->total_cents),
                'status' => $this->labPurchaseStatus($purchase),
                'origin' => $purchase->brand?->label() ?: self::UNAVAILABLE,
                'sort' => optional($purchase->created_at)?->timestamp ?? 0,
            ]);
        }

        foreach ($pharmacy as $purchase) {
            $rows->push([
                'id' => 'pharmacy-'.$purchase->id,
                'date' => $this->formatDate($purchase->created_at),
                'type' => 'Farmacia',
                'amount' => $this->money($purchase->total_cents),
                'status' => $purchase->trashed() ? 'Cancelada' : 'Completada',
                'origin' => 'Farmacia en línea',
                'sort' => optional($purchase->created_at)?->timestamp ?? 0,
            ]);
        }

        return $rows
            ->sortByDesc('sort')
            ->values()
            ->take(self::LIMIT)
            ->map(function (array $row) {
                unset($row['sort']);

                return $row;
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function laboratories(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        return LaboratoryPurchase::query()
            ->withTrashed()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'brand', 'created_at', 'total_cents', 'results', 'ready_at', 'completed_at', 'deleted_at'])
            ->map(function (LaboratoryPurchase $purchase) {
                $hasResults = filled($purchase->results) || filled($purchase->ready_at);

                return [
                    'id' => $purchase->id,
                    'date' => $this->formatDate($purchase->created_at),
                    'status' => $this->labPurchaseStatus($purchase),
                    'results_available' => $hasResults ? 'Sí' : 'No',
                    'provider' => $purchase->brand?->label() ?: self::UNAVAILABLE,
                    'amount' => $this->money($purchase->total_cents),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function memberships(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        $subs = MedicalAttentionSubscription::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'type', 'start_date', 'end_date', 'parent_subscription_id', 'created_at']);

        $ids = $subs->pluck('id')->all();
        $renewalCounts = $ids === []
            ? collect()
            : MedicalAttentionSubscription::query()
                ->whereIn('parent_subscription_id', $ids)
                ->selectRaw('parent_subscription_id, COUNT(*) as aggregate')
                ->groupBy('parent_subscription_id')
                ->pluck('aggregate', 'parent_subscription_id');

        return $subs->map(function (MedicalAttentionSubscription $subscription) use ($renewalCounts) {
            $renewals = (int) ($renewalCounts[$subscription->id] ?? 0);

            return [
                'id' => $subscription->id,
                'status' => $subscription->is_active ? 'Activa' : 'Inactiva',
                'type' => $subscription->type?->label() ?: self::UNAVAILABLE,
                'start_date' => $subscription->start_date
                    ? $this->formatDate(Carbon::parse($subscription->start_date))
                    : self::UNAVAILABLE,
                'end_date' => $subscription->end_date
                    ? $this->formatDate(Carbon::parse($subscription->end_date))
                    : self::UNAVAILABLE,
                'renewals' => (string) $renewals,
                'active_benefits' => self::UNAVAILABLE,
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoices(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        return Invoice::query()
            ->with([
                'invoiceable' => function ($morphTo) {
                    $morphTo->morphWith([
                        LaboratoryPurchase::class => ['invoiceRequest'],
                        OnlinePharmacyPurchase::class => ['invoiceRequest'],
                    ]);
                },
            ])
            ->whereHasMorph(
                'invoiceable',
                [LaboratoryPurchase::class, OnlinePharmacyPurchase::class],
                fn ($q) => $q->where('customer_id', $customer->id)
            )
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (Invoice $invoice) {
                $purchase = $invoice->invoiceable;
                $rfc = null;
                $amount = self::UNAVAILABLE;

                if ($purchase instanceof LaboratoryPurchase || $purchase instanceof OnlinePharmacyPurchase) {
                    $rfc = $purchase->invoiceRequest?->rfc;
                    $amount = $this->money($purchase->total_cents ?? null);
                }

                return [
                    'id' => $invoice->id,
                    'status' => $invoice->completed_at ? 'Completada' : 'En proceso',
                    'rfc' => filled($rfc) ? $rfc : self::UNAVAILABLE,
                    'date' => $this->formatDate($invoice->completed_at ?? $invoice->created_at),
                    'amount' => $amount,
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function coupons(?Customer $customer): array
    {
        if (! $customer?->user_id) {
            return [];
        }

        return CouponUser::query()
            ->with('coupon:id,code,remaining_cents,expires_at,is_active,amount_cents,valid_from')
            ->where('user_id', $customer->user_id)
            ->orderByDesc('assigned_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (CouponUser $row) {
                $coupon = $row->coupon;
                $bucket = $this->couponBucket($row, $coupon);

                return [
                    'id' => $row->coupon_id.'-'.$row->user_id,
                    'code' => $coupon?->code ?: self::UNAVAILABLE,
                    'bucket' => $bucket,
                    'bucket_label' => match ($bucket) {
                        'used' => 'Utilizado',
                        'expired' => 'Expirado',
                        'available' => 'Disponible',
                        'assigned' => 'Asignado',
                        default => self::UNAVAILABLE,
                    },
                    'assigned_at' => $row->assigned_at
                        ? $this->formatDate($row->assigned_at)
                        : self::UNAVAILABLE,
                    'used_at' => $row->used_at
                        ? $this->formatDate($row->used_at)
                        : self::UNAVAILABLE,
                    'expires_at' => $coupon?->expires_at
                        ? $this->formatDate($coupon->expires_at)
                        : self::UNAVAILABLE,
                    'remaining' => $coupon
                        ? $this->money((int) $coupon->remaining_cents)
                        : self::UNAVAILABLE,
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function beneficiaries(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        return FamilyAccount::query()
            ->withTrashed()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'paternal_lastname', 'maternal_lastname', 'kinship', 'created_at', 'deleted_at'])
            ->map(function (FamilyAccount $member) {
                return [
                    'id' => $member->id,
                    'name' => trim((string) $member->full_name) ?: self::UNAVAILABLE,
                    'kinship' => $member->formatted_kinship ?: self::UNAVAILABLE,
                    'status' => $member->trashed() ? 'Inactivo' : 'Activo',
                    'registered_at' => $this->formatDate($member->created_at),
                ];
            })
            ->all();
    }

    private function couponBucket(CouponUser $row, mixed $coupon): string
    {
        if ($row->used_at) {
            return 'used';
        }

        if ($coupon && method_exists($coupon, 'isExpired') && $coupon->isExpired()) {
            return 'expired';
        }

        if ($coupon && (int) $coupon->remaining_cents > 0 && ($coupon->is_active ?? true)) {
            return 'available';
        }

        if ($row->assigned_at) {
            return 'assigned';
        }

        return 'unavailable';
    }

    private function labPurchaseStatus(LaboratoryPurchase $purchase): string
    {
        if ($purchase->trashed()) {
            return 'Cancelada';
        }

        if ($purchase->completed_at) {
            return 'Completada';
        }

        return 'Registrada';
    }

    private function formatDate(mixed $date): string
    {
        if (! $date) {
            return self::UNAVAILABLE;
        }

        $carbon = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        return $carbon->timezone(self::TZ)->format('d/m/Y');
    }

    private function money(?int $cents): string
    {
        if ($cents === null) {
            return self::UNAVAILABLE;
        }

        if (function_exists('formattedCentsPrice')) {
            return formattedCentsPrice($cents);
        }

        return '$'.number_format($cents / 100, 2);
    }
}
