<?php

namespace App\Services\ActiveCampaign;

use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Customer Journey visual — única fuente de eventos: TimelineService.
 */
class ActiveCampaignCustomerJourneyService
{
    private const TZ = 'America/Monterrey';

    private const CACHE_SECONDS = 90;

    private ActiveCampaignContactTimelineService $timeline;

    public function __construct(ActiveCampaignContactTimelineService $timeline)
    {
        $this->timeline = $timeline;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPage(Request $request): array
    {
        $contactId = $request->integer('contact_id') ?: null;
        $filters = [
            'contact_id' => $contactId,
            'search' => trim((string) $request->input('search', '')),
            'start_date' => (string) $request->input('start_date', ''),
            'end_date' => (string) $request->input('end_date', ''),
            'type' => (string) $request->input('type', ''),
        ];

        $contactOptions = $this->searchContacts($filters['search']);

        if (! $contactId) {
            return [
                'filters' => $filters,
                'contactOptions' => $contactOptions,
                'journey' => null,
                'journeyDetail' => null,
            ];
        }

        $contact = Contact::query()
            ->with(['customer.user:id,email'])
            ->find($contactId);

        if (! $contact) {
            return [
                'filters' => $filters,
                'contactOptions' => $contactOptions,
                'journey' => null,
                'journeyDetail' => null,
            ];
        }

        $timeline = $this->timelineCached($contact, $filters);
        $journey = $this->mapTimelineToJourney($contact, $timeline, $filters);

        return [
            'filters' => [
                ...$filters,
                'contact_id' => $contact->id,
            ],
            'contactOptions' => $contactOptions,
            'journey' => $journey,
            'journeyDetail' => null,
        ];
    }

    /**
     * Detalle de nodo bajo demanda (reutiliza cache del Timeline).
     *
     * @return array<string, mixed>|null
     */
    public function buildNodeDetail(int $contactId, string $nodeId, array $filters = []): ?array
    {
        $contact = Contact::query()->find($contactId);
        if (! $contact) {
            return null;
        }

        $timeline = $this->timelineCached($contact, $filters);
        $event = collect($timeline['events'])->firstWhere('id', $nodeId);

        if ($event) {
            return [
                'node_id' => $nodeId,
                'kind' => 'event',
                'title' => $event['type_label'],
                'description' => $event['description'],
                'origin' => $event['source_label'],
                'source' => $event['source'],
                'table' => $this->tableForType($event['type']),
                'model' => $this->modelForType($event['type']),
                'date' => $event['date'].' '.$event['time'],
                'status' => $event['status_label'],
                'badge' => $event['badge'],
                'color' => $event['color'],
                'truth' => 'disponible',
                'actions' => [
                    'Abrir en Vista 360 (próximamente desde Journey)',
                    'Exportar evento (próximamente)',
                ],
                'raw' => [
                    'type' => $event['type'],
                    'status' => $event['status'],
                    'occurred_at' => $event['occurred_at'],
                ],
            ];
        }

        $upcoming = collect($timeline['upcoming'])->firstWhere('type', $nodeId);
        if ($upcoming) {
            return [
                'node_id' => $nodeId,
                'kind' => 'planned',
                'title' => $upcoming['label'],
                'description' => $upcoming['reason'],
                'origin' => 'No instrumentado',
                'source' => 'planned',
                'table' => 'No disponible',
                'model' => 'No disponible',
                'date' => 'No disponible',
                'status' => 'Próximamente',
                'badge' => 'Próximamente',
                'color' => 'zinc',
                'truth' => 'proximamente',
                'actions' => ['Requiere instrumentación'],
                'raw' => $upcoming,
            ];
        }

        return null;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function searchContacts(string $search): array
    {
        $query = Contact::query()->latest('id')->limit(12);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('paternal_lastname', 'like', "%{$search}%")
                    ->orWhere('maternal_lastname', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return $query->get(['id', 'name', 'paternal_lastname', 'maternal_lastname'])
            ->map(fn (Contact $c) => [
                'id' => $c->id,
                'label' => trim($c->full_name).' · #'.$c->id,
            ])
            ->all();
    }

    /**
     * @param  array{start_date?: string, end_date?: string, type?: string, search?: string, contact_id?: int|null}  $filters
     * @return array<string, mixed>
     */
    private function timelineCached(Contact $contact, array $filters = []): array
    {
        $start = ($filters['start_date'] ?? '') !== ''
            ? Carbon::parse($filters['start_date'], self::TZ)->startOfDay()->utc()
            : null;
        $end = ($filters['end_date'] ?? '') !== ''
            ? Carbon::parse($filters['end_date'], self::TZ)->endOfDay()->utc()
            : null;

        $key = 'mi-journey-tl:v2:'.$contact->id.':'.sha1(($start?->toIso8601String() ?? '').'|'.($end?->toIso8601String() ?? ''));

        return Cache::remember(
            $key,
            now()->addSeconds(self::CACHE_SECONDS),
            fn () => $this->timeline->buildForContact($contact, $start, $end)
        );
    }

    /**
     * @param  array<string, mixed>  $timeline
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function mapTimelineToJourney(Contact $contact, array $timeline, array $filters): array
    {
        $events = collect($timeline['events'] ?? []);

        if ($filters['type'] !== '') {
            $events = $events->where('type', $filters['type']);
        }

        // Journey visual: cronológico ascendente (inicio → fin).
        $ordered = $events->sortBy('occurred_at')->values();

        $nodes = [];
        $edges = [];
        $index = 0;

        foreach ($ordered as $event) {
            $nodeId = $event['id'];
            $nodes[] = [
                'id' => $nodeId,
                'label' => $event['type_label'],
                'type' => $event['type'],
                'date' => $event['date'],
                'time' => $event['time'],
                'status' => $event['status_label'],
                'origin' => $event['source_label'],
                'truth' => 'disponible',
                'badge' => $event['badge'],
                'icon' => $event['icon'],
                'color' => $event['color'],
                'x' => 40 + ($index * 200),
                'y' => 70 + (($index % 2) * 70),
            ];

            if ($index > 0) {
                $prev = $nodes[$index - 1];
                $hours = $this->hoursBetween(
                    $ordered[$index - 1]['occurred_at'],
                    $event['occurred_at']
                );
                $edges[] = [
                    'id' => 'edge-'.$prev['id'].'-'.$nodeId,
                    'from' => $prev['id'],
                    'to' => $nodeId,
                    'from_x' => $prev['x'] + 72,
                    'from_y' => $prev['y'] + 36,
                    'to_x' => $nodes[$index]['x'] + 72,
                    'to_y' => $nodes[$index]['y'] + 36,
                    'label' => $hours,
                ];
            }

            $index++;
        }

        foreach ($timeline['upcoming'] ?? [] as $i => $planned) {
            $nodeId = $planned['type'];
            $nodes[] = [
                'id' => $nodeId,
                'label' => $planned['label'],
                'type' => $planned['type'],
                'date' => '—',
                'time' => '',
                'status' => 'No instrumentado',
                'origin' => $planned['reason'],
                'truth' => 'proximamente',
                'badge' => 'Próximamente',
                'icon' => 'bolt',
                'color' => 'zinc',
                'x' => 40 + ($index * 200),
                'y' => 70 + (($index % 2) * 70),
                'planned' => true,
            ];

            if ($index > 0) {
                $prev = $nodes[$index - 1];
                $edges[] = [
                    'id' => 'edge-planned-'.$i,
                    'from' => $prev['id'],
                    'to' => $nodeId,
                    'from_x' => $prev['x'] + 72,
                    'from_y' => $prev['y'] + 36,
                    'to_x' => $nodes[$index]['x'] + 72,
                    'to_y' => $nodes[$index]['y'] + 36,
                    'label' => '—',
                    'dashed' => true,
                ];
            }
            $index++;
        }

        $realEvents = $ordered;
        $last = $realEvents->last();
        $first = $realEvents->first();

        $email = $contact->customer?->user?->email;

        return [
            'summary' => [
                'patient' => trim((string) $contact->full_name) ?: 'Paciente #'.$contact->id,
                'email' => $email ?: 'No disponible',
                'contact_id' => $contact->id,
                'period' => $this->periodLabel($filters, $first, $last),
                'status' => $realEvents->isEmpty()
                    ? 'Sin eventos'
                    : ($last['type_label'] ?? 'En curso'),
                'events_count' => $realEvents->count(),
                'last_activity' => $last
                    ? ($last['date'].' '.$last['time'])
                    : 'No disponible',
            ],
            'nodes' => $nodes,
            'edges' => $edges,
            'stats' => $this->buildStats($realEvents, $first, $last),
            'type_options' => $realEvents
                ->map(fn (array $e) => [
                    'value' => $e['type'],
                    'label' => $e['type_label'],
                ])
                ->unique('value')
                ->values()
                ->all(),
            'canvas' => [
                'width' => max(640, 40 + ($index * 200) + 80),
                'height' => 280,
            ],
            'meta' => [
                'source' => 'timeline',
                'reusable_for' => ['customer_journey', 'analytics', 'ai'],
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function buildStats($events, ?array $first, ?array $last): array
    {
        $span = 'No disponible';
        if ($first && $last && isset($first['occurred_at'], $last['occurred_at'])) {
            $hours = Carbon::parse($first['occurred_at'])->diffInHours(Carbon::parse($last['occurred_at']));
            $span = $hours < 24
                ? $hours.' h entre primer y último evento'
                : round($hours / 24, 1).' días de recorrido';
        }

        return [
            'totals' => $events->count(),
            'span_label' => $span,
            'purchases' => $events->whereIn('type', ['laboratory_purchase', 'pharmacy_purchase'])->count(),
            'laboratories' => $events->where('type', 'laboratory_purchase')->count(),
            'invoices' => $events->where('type', 'invoice')->count(),
            'memberships' => $events->where('type', 'membership')->count(),
            'results' => $events->where('type', 'laboratory_results')->count(),
            'beneficiaries' => $events->where('type', 'beneficiary_added')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function periodLabel(array $filters, ?array $first, ?array $last): string
    {
        if ($filters['start_date'] !== '' || $filters['end_date'] !== '') {
            $from = $filters['start_date'] !== '' ? $filters['start_date'] : '…';
            $to = $filters['end_date'] !== '' ? $filters['end_date'] : '…';

            return "{$from} → {$to}";
        }

        if ($first && $last) {
            return $first['date'].' → '.$last['date'];
        }

        return 'Sin periodo';
    }

    private function hoursBetween(string $from, string $to): string
    {
        $hours = Carbon::parse($from)->diffInHours(Carbon::parse($to));
        if ($hours < 1) {
            return '<1h';
        }
        if ($hours < 48) {
            return $hours.'h';
        }

        return round($hours / 24, 1).'d';
    }

    private function tableForType(string $type): string
    {
        return match ($type) {
            'registration' => 'contacts',
            'laboratory_purchase', 'laboratory_results' => 'laboratory_purchases',
            'pharmacy_purchase' => 'online_pharmacy_purchases',
            'invoice' => 'invoices',
            'membership' => 'medical_attention_subscriptions',
            'beneficiary_added' => 'family_accounts',
            'coupon_assigned' => 'coupon_user',
            'activecampaign_dispatch' => 'activecampaign_dispatches',
            default => 'No disponible',
        };
    }

    private function modelForType(string $type): string
    {
        return match ($type) {
            'registration' => 'Contact',
            'laboratory_purchase', 'laboratory_results' => 'LaboratoryPurchase',
            'pharmacy_purchase' => 'OnlinePharmacyPurchase',
            'invoice' => 'Invoice',
            'membership' => 'MedicalAttentionSubscription',
            'beneficiary_added' => 'FamilyAccount',
            'coupon_assigned' => 'CouponUser',
            'activecampaign_dispatch' => 'ActiveCampaignDispatch',
            default => 'No disponible',
        };
    }
}
