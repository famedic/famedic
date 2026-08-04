<?php

namespace App\Services\ActiveCampaign;

use App\Models\Contact;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Listado ligero de Contactos para Marketing Intelligence.
 * El detalle del Drawer 360 se carga bajo demanda (summary primero).
 */
class ActiveCampaignContactsService
{
    private const UNAVAILABLE = 'No disponible';

    /**
     * @return array{contacts: LengthAwarePaginator, filters: array<string, mixed>}
     */
    public function list(Request $request): array
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'tag' => (string) $request->input('tag', ''),
            'status' => (string) $request->input('status', ''),
            'membership' => (string) $request->input('membership', ''),
            'laboratory' => (string) $request->input('laboratory', ''),
            'start_date' => (string) $request->input('start_date', ''),
            'end_date' => (string) $request->input('end_date', ''),
        ];

        $query = Contact::query()
            ->with([
                'customer:id,user_id,medical_attention_subscription_expires_at',
                'customer.user:id,email',
                'customer.addresses:id,customer_id,city',
            ])
            ->latest('id');

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('paternal_lastname', 'like', "%{$search}%")
                    ->orWhere('maternal_lastname', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('customer.user', function ($userQuery) use ($search) {
                        $userQuery->where('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($filters['membership'] === 'active') {
            $query->whereHas('customer', function ($q) {
                $q->where('medical_attention_subscription_expires_at', '>', now());
            });
        } elseif ($filters['membership'] === 'inactive') {
            $query->where(function ($q) {
                $q->whereDoesntHave('customer')
                    ->orWhereHas('customer', function ($customerQuery) {
                        $customerQuery->where(function ($inner) {
                            $inner->whereNull('medical_attention_subscription_expires_at')
                                ->orWhere('medical_attention_subscription_expires_at', '<=', now());
                        });
                    });
            });
        }

        if ($filters['start_date'] !== '') {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if ($filters['end_date'] !== '') {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        $contacts = $query
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Contact $contact) => $this->mapRow($contact));

        return [
            'contacts' => $contacts,
            'filters' => $filters,
        ];
    }

    /**
     * Payload del Drawer 360 (solo Summary hidratado; resto listo para crecer).
     *
     * @return array<string, mixed>|null
     */
    public function buildDrawerSummary(int $contactId): ?array
    {
        $contact = Contact::query()
            ->with([
                'customer:id,user_id,medical_attention_subscription_expires_at',
                'customer.user:id,email',
                'customer.addresses:id,customer_id,city',
            ])
            ->find($contactId);

        if (! $contact) {
            return null;
        }

        $customer = $contact->customer;
        $email = $customer?->user?->email;
        $city = $customer?->addresses?->first()?->city;
        $phone = $contact->phone_for_display;
        $name = trim((string) $contact->full_name);

        $membership = $this->resolveMembership($customer);
        $purchase = $this->resolveLastPurchase($customer?->id);
        $beneficiariesCount = $customer
            ? (int) $customer->familyMembers()->count()
            : null;

        return [
            'contact_id' => $contact->id,
            'summary' => [
                'name' => $name !== '' ? $name : self::UNAVAILABLE,
                'initials' => $this->initials($name),
                'email' => filled($email) ? $email : self::UNAVAILABLE,
                'phone' => filled($phone) ? $phone : self::UNAVAILABLE,
                'city' => filled($city) ? $city : self::UNAVAILABLE,
                'status' => 'Activo',
                'registered_at' => $contact->created_at
                    ? $this->formatDateTime($contact->created_at)
                    : self::UNAVAILABLE,
                'last_activity' => self::UNAVAILABLE,
                'last_purchase' => $purchase['label'],
                'membership' => $membership,
                'laboratory' => $purchase['laboratory'],
                'beneficiaries_count' => $beneficiariesCount,
                'tags_count' => null,
                'automations' => self::UNAVAILABLE,
            ],
            // Timeline se hidrata en drawerExtras (P1-4) para no bloquear el summary.
            'timeline' => null,
            'events' => null,
            'tags' => null,
            'purchases' => null,
            'laboratories' => null,
            'memberships' => null,
            'invoices' => null,
            'coupons' => null,
            'beneficiaries' => null,
            'automations' => null,
            'insights' => null,
        ];
    }

    /**
     * Bundle único: Timeline + secciones lazy (un solo partial reload).
     *
     * @return array<string, mixed>|null
     */
    public function buildDrawerExtras(
        int $contactId,
        ActiveCampaignContactTimelineService $timeline,
        ActiveCampaignContactDrawerSectionsService $sections,
    ): ?array {
        $contact = Contact::query()
            ->with(['customer.user:id,email'])
            ->find($contactId);

        if (! $contact) {
            return null;
        }

        $sectionPayloads = [];
        foreach (ActiveCampaignContactDrawerSectionsService::SECTIONS as $section) {
            $sectionPayloads[$section] = $sections->build($contactId, $section);
        }

        return [
            'contact_id' => $contact->id,
            'timeline' => $timeline->buildForContact($contact),
            'sections' => $sectionPayloads,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(Contact $contact): array
    {
        $customer = $contact->customer;
        $email = $customer?->user?->email;
        $city = $customer?->addresses?->first()?->city;
        $membershipActive = $customer
            && $customer->medical_attention_subscription_expires_at
            && now()->lte($customer->medical_attention_subscription_expires_at);

        $membershipLabel = ! $customer
            ? '—'
            : ($membershipActive ? 'Activa' : 'Inactiva');

        return [
            'id' => $contact->id,
            'customer_id' => $contact->customer_id,
            'name' => $contact->full_name ?: '—',
            'email' => $email ?: '—',
            'phone' => $contact->phone_for_display ?: '—',
            'city' => filled($city) ? $city : '—',
            'last_activity' => '—',
            'last_purchase' => '—',
            'laboratory' => '—',
            'membership' => $membershipLabel,
            'membership_active' => $membershipActive,
            'status' => '—',
            'tags' => [],
            'created_at' => $contact->created_at
                ? $contact->created_at->timezone('America/Monterrey')->format('d/m/Y')
                : null,
        ];
    }

    private function resolveMembership(mixed $customer): string
    {
        if (! $customer) {
            return self::UNAVAILABLE;
        }

        if (! $customer->medical_attention_subscription_expires_at) {
            return 'Sin membresía';
        }

        $expires = $customer->medical_attention_subscription_expires_at;
        $active = now()->lte($expires);
        $expiresLabel = $this->formatDate($expires);

        return $active
            ? "Activa · vence {$expiresLabel}"
            : "Inactiva · venció {$expiresLabel}";
    }

    /**
     * @return array{label: string, laboratory: string}
     */
    private function resolveLastPurchase(?int $customerId): array
    {
        if (! $customerId) {
            return [
                'label' => self::UNAVAILABLE,
                'laboratory' => self::UNAVAILABLE,
            ];
        }

        $lastLab = LaboratoryPurchase::query()
            ->where('customer_id', $customerId)
            ->latest('id')
            ->first(['id', 'brand', 'created_at', 'total_cents']);

        $lastPharmacy = OnlinePharmacyPurchase::query()
            ->where('customer_id', $customerId)
            ->latest('id')
            ->first(['id', 'created_at', 'total_cents']);

        $candidates = [];

        if ($lastLab?->created_at) {
            $brand = $lastLab->brand?->label() ?: 'Laboratorio';
            $candidates[] = [
                'at' => $lastLab->created_at,
                'label' => trim($brand.' · '.$this->formatDate($lastLab->created_at).' · '.$this->formatMoney($lastLab->total_cents)),
                'laboratory' => $brand,
            ];
        }

        if ($lastPharmacy?->created_at) {
            $candidates[] = [
                'at' => $lastPharmacy->created_at,
                'label' => trim('Farmacia · '.$this->formatDate($lastPharmacy->created_at).' · '.$this->formatMoney($lastPharmacy->total_cents)),
                'laboratory' => null,
            ];
        }

        if ($candidates === []) {
            return [
                'label' => self::UNAVAILABLE,
                'laboratory' => self::UNAVAILABLE,
            ];
        }

        usort($candidates, fn ($a, $b) => $b['at'] <=> $a['at']);
        $winner = $candidates[0];

        return [
            'label' => $winner['label'],
            'laboratory' => $winner['laboratory'] ?? (
                $lastLab?->brand?->label() ?: self::UNAVAILABLE
            ),
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return '?';
        }

        $first = mb_substr($parts[0], 0, 1);
        $second = isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '';

        return mb_strtoupper($first.$second);
    }

    private function formatDate(Carbon $date): string
    {
        return $date->timezone('America/Monterrey')->format('d/m/Y');
    }

    private function formatDateTime(Carbon $date): string
    {
        return $date->timezone('America/Monterrey')->format('d/m/Y H:i');
    }

    private function formatMoney(?int $cents): string
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
