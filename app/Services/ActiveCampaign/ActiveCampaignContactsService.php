<?php

namespace App\Services\ActiveCampaign;

use App\Models\Contact;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
     * Bundle único: Timeline + secciones lazy + espejo ActiveCampaign (un solo partial reload).
     *
     * @return array<string, mixed>|null
     */
    public function buildDrawerExtras(
        int $contactId,
        ActiveCampaignContactTimelineService $timeline,
        ActiveCampaignContactDrawerSectionsService $sections,
        ActiveCampaignMirrorService $mirror,
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
            'mirror' => $this->buildMirrorPayload($contact, $mirror),
        ];
    }

    /**
     * Espejo AC para el Drawer 360 (Tags, Eventos, Automations + Fase 2).
     * Nunca lanza: un fallo de API no debe romper el CRM Famedic.
     *
     * @return array<string, mixed>
     */
    private function buildMirrorPayload(Contact $contact, ActiveCampaignMirrorService $mirror): array
    {
        $empty = [
            'status' => 'missing',
            'message' => null,
            'synced_at' => null,
            'synced_at_human' => null,
            'from_cache' => false,
            'ac_contact_id' => null,
            'tags' => [],
            'activities' => [],
            'automations' => [],
            'lists' => [],
            'fields' => [],
            'lead_score' => null,
            'engagement' => null,
            'owner' => null,
        ];

        $customer = $contact->customer;
        if (! $customer) {
            return [
                ...$empty,
                'status' => 'missing',
                'message' => 'Este contacto no tiene customer asociado en Famedic.',
            ];
        }

        try {
            $snapshot = $mirror->snapshot($customer);
        } catch (\Throwable $e) {
            Log::warning('AC Mirror Drawer: fallo al obtener snapshot', [
                'contact_id' => $contact->id,
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return [
                ...$empty,
                'status' => 'error',
                'message' => 'No fue posible obtener la información de ActiveCampaign.',
            ];
        }

        if ($snapshot === null) {
            return [
                ...$empty,
                'status' => 'missing',
                'message' => 'No fue posible obtener la información de ActiveCampaign.',
            ];
        }

        $tags = array_map(
            static fn ($tag) => $tag->toArray(),
            $snapshot->tags
        );
        usort($tags, static fn (array $a, array $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        $activities = array_map(
            fn ($activity) => $this->mapActivityForDrawer($activity->toArray()),
            $snapshot->activities
        );

        $automations = array_map(
            fn ($automation) => $this->mapAutomationForDrawer($automation->toArray()),
            $snapshot->automations
        );

        $lists = array_map(
            fn ($list) => $this->mapListForDrawer($list->toArray()),
            $snapshot->lists
        );

        $fieldsSource = $snapshot->relevantFields !== []
            ? $snapshot->relevantFields
            : [];
        $fields = array_map(
            static fn ($field) => $field->toArray(),
            $fieldsSource
        );

        $lead = $snapshot->leadScoreSummary();
        $engagement = ($snapshot->engagement ?? \App\DataTransferObjects\ActiveCampaign\ContactEngagementData::unavailable())->toArray();

        return [
            'status' => 'ok',
            'message' => null,
            'synced_at' => $snapshot->mirroredAt->toIso8601String(),
            'synced_at_human' => $snapshot->mirroredAt->locale('es')->diffForHumans(),
            'from_cache' => $snapshot->fromCache,
            'ac_contact_id' => $snapshot->acContactId,
            'tags' => array_values($tags),
            'activities' => array_values($activities),
            'automations' => array_values($automations),
            'lists' => array_values($lists),
            'fields' => array_values($fields),
            'lead_score' => [
                'total' => $lead->total,
                'primary' => $lead->primary?->toArray(),
                'updated_at' => $this->formatMirrorTimestamp($lead->updatedAt),
                'classification' => $lead->classification,
                'scores' => array_map(
                    static fn ($s) => $s->toArray(),
                    $lead->scores
                ),
            ],
            'engagement' => [
                'emails_sent' => $this->formatEngagementValue($engagement['emails_sent'] ?? null),
                'last_open' => $this->formatEngagementTimestamp($engagement['last_open'] ?? null),
                'last_click' => $this->formatEngagementTimestamp($engagement['last_click'] ?? null),
                'open_rate' => $this->formatEngagementRate($engagement['open_rate'] ?? null),
                'click_rate' => $this->formatEngagementRate($engagement['click_rate'] ?? null),
                'last_campaign' => $this->formatEngagementValue($engagement['last_campaign'] ?? null),
            ],
            'owner' => $snapshot->owner?->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $list
     * @return array<string, mixed>
     */
    private function mapListForDrawer(array $list): array
    {
        return [
            'id' => $list['contact_list_id'] ?? $list['list_id'] ?? null,
            'name' => filled($list['name'] ?? null)
                ? (string) $list['name']
                : ('Lista #'.($list['list_id'] ?? '—')),
            'status' => $list['status'] ?? '—',
            'joined_at' => $this->formatMirrorTimestamp(
                is_string($list['sdate'] ?? null) ? $list['sdate'] : null
            ),
        ];
    }

    private function formatEngagementValue(mixed $value): string
    {
        if ($value === null || $value === '' || $value === 'No disponible') {
            return 'No disponible';
        }

        return is_scalar($value) ? (string) $value : 'No disponible';
    }

    private function formatEngagementTimestamp(mixed $value): string
    {
        if ($value === null || $value === '' || $value === 'No disponible') {
            return 'No disponible';
        }

        if (! is_string($value)) {
            return 'No disponible';
        }

        return $this->formatMirrorTimestamp($value);
    }

    private function formatEngagementRate(mixed $value): string
    {
        if ($value === null || $value === '' || $value === 'No disponible') {
            return 'No disponible';
        }

        if (is_numeric($value)) {
            return ((int) $value).'%';
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    private function mapActivityForDrawer(array $activity): array
    {
        $type = (string) ($activity['reference_model_name'] ?? $activity['type'] ?? 'actividad');
        $action = trim((string) ($activity['reference_action'] ?? ''));
        $tstamp = $activity['tstamp'] ?? null;

        $label = $this->activityTypeLabel($type, $action);
        $description = $this->activityDescription($type, $action, $activity);

        return [
            'id' => $activity['id'] ?? null,
            'icon' => $this->activityIconKey($type, $action),
            'type' => $label,
            'description' => $description,
            'tstamp' => $tstamp,
            'date' => $this->formatMirrorTimestamp(is_string($tstamp) ? $tstamp : null),
            'reference_type' => $activity['reference_type'] ?? null,
            'reference_action' => $action !== '' ? $action : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $automation
     * @return array<string, mixed>
     */
    private function mapAutomationForDrawer(array $automation): array
    {
        return [
            'id' => $automation['contact_automation_id'] ?? $automation['automation_id'] ?? null,
            'name' => filled($automation['name'] ?? null)
                ? (string) $automation['name']
                : ('Automatización #'.($automation['automation_id'] ?? '—')),
            'status' => $this->automationStatusLabel($automation['status'] ?? null),
            'status_raw' => $automation['status'] ?? null,
            'entered_at' => $this->formatMirrorTimestamp(
                is_string($automation['add_date'] ?? null) ? $automation['add_date'] : null
            ),
            'updated_at' => $this->formatMirrorTimestamp(
                is_string($automation['last_date'] ?? null) ? $automation['last_date'] : null
            ),
            'complete_value' => $automation['complete_value'] ?? null,
        ];
    }

    private function activityTypeLabel(string $type, string $action): string
    {
        $normalized = mb_strtolower($type);

        return match (true) {
            str_contains($normalized, 'email') && $action === 'open' => 'Email abierto',
            str_contains($normalized, 'email') && $action === 'click' => 'Click en email',
            str_contains($normalized, 'email') => 'Email',
            str_contains($normalized, 'tag') => 'Tag',
            str_contains($normalized, 'list') => 'Lista',
            str_contains($normalized, 'automat') => 'Automatización',
            str_contains($normalized, 'note') => 'Nota',
            str_contains($normalized, 'deal') => 'Deal',
            default => $type !== '' ? $type : 'Actividad',
        };
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function activityDescription(string $type, string $action, array $activity): string
    {
        $label = $this->activityTypeLabel($type, $action);
        $refId = $activity['reference_id'] ?? null;

        if ($action !== '' && ! in_array($action, ['open', 'click'], true)) {
            return trim($label.' · '.$action.($refId ? " (#{$refId})" : ''));
        }

        return $refId ? "{$label} · ref #{$refId}" : $label;
    }

    private function activityIconKey(string $type, string $action): string
    {
        $normalized = mb_strtolower($type);

        return match (true) {
            str_contains($normalized, 'email') && $action === 'open' => 'mail-open',
            str_contains($normalized, 'email') && $action === 'click' => 'cursor-click',
            str_contains($normalized, 'email') => 'mail',
            str_contains($normalized, 'tag') => 'tag',
            str_contains($normalized, 'list') => 'list',
            str_contains($normalized, 'automat') => 'bolt',
            default => 'activity',
        };
    }

    private function automationStatusLabel(mixed $status): string
    {
        return match ((string) $status) {
            '0' => 'Inactiva',
            '1' => 'Activa',
            '2' => 'Completada',
            default => filled($status) ? (string) $status : 'Desconocido',
        };
    }

    private function formatMirrorTimestamp(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return self::UNAVAILABLE;
        }

        try {
            return $this->formatDateTime(Carbon::parse($value));
        } catch (\Throwable) {
            return $value;
        }
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
