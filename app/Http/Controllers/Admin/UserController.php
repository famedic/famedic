<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Users\UpdateAdminUserAction;
use App\Actions\BuildUserAdminChartDataAction;
use App\Data\StatesMexico;
use App\Enums\Gender;
use App\Enums\MonitoringCartType;
use App\Enums\MonitoringCartStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\UpdateUserRequest;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\ActiveCampaignDispatch;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Services\CouponBeneficiaryService;
use App\Support\Database\PersonNameSearch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request, BuildUserAdminChartDataAction $buildUserAdminChartDataAction)
    {
        $request->user()->administrator->hasPermissionTo('users.manage') || abort(403);

        $view = $request->get('view', 'list');

        $filters = collect($request->only([
            'search',
            'verified',
            'start_date',
            'end_date',
        ]))->filter()->all();

        $filters['view'] = $view;

        $query = User::query()
            ->when($filters['search'] ?? null, function ($query, string $search) {
                PersonNameSearch::apply($query, $search, 'users', ['email', 'phone']);
            })
            ->when($filters['verified'] ?? null, function ($query, string $verified) {
                if ($verified === 'verified') {
                    $query->whereNotNull('email_verified_at')
                        ->whereNotNull('phone_verified_at');
                } elseif ($verified === 'unverified') {
                    $query->where(function ($q) {
                        $q->whereNull('email_verified_at')
                            ->orWhereNull('phone_verified_at');
                    });
                }
            })
            ->orderByDesc('created_at');

        $users = $query
            ->withCount([
                'referrals',
                'monitoringCarts as active_carts_count' => function ($q) {
                    $q->where('status', '!=', MonitoringCartStatus::Completed)
                        ->whereHas('items');
                },
            ])
            ->paginate(25)
            ->withQueryString();

        $chart = null;
        if ($view === 'chart') {
            $start = $request->filled('start_date')
                ? Carbon::parse($request->start_date, 'America/Monterrey')->startOfDay()
                : null;
            $end = $request->filled('end_date')
                ? Carbon::parse($request->end_date, 'America/Monterrey')->endOfDay()
                : null;
            $chart = $buildUserAdminChartDataAction($start, $end);

            if (! $request->filled('start_date')) {
                $filters['start_date'] = $chart['startDate'];
            }
            if (! $request->filled('end_date')) {
                $filters['end_date'] = $chart['endDate'];
            }
        }

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => $filters,
            'chart' => $chart,
        ]);
    }

    public function show(User $user)
    {
        request()->user()->administrator->hasPermissionTo('users.manage') || abort(403);

        $user->load([
            'pendingLaboratoryResults',
            'referrer',
            'referrals',
        ]);

        // Para admin: si el customer está soft-deleted, User::customer() no lo trae.
        // Usamos withTrashed para no perder direcciones / compras / etc.
        // Orden: el registro más reciente (por si hubiera más de un customer con el mismo user_id).
        $customer = Customer::withTrashed()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->with([
                'addresses',
                'contacts' => function ($query) {
                    $query->withTrashed();
                },
                'taxProfiles' => function ($query) {
                    $query->withTrashed();
                },
                'laboratoryPurchases' => function ($query) {
                    $query->latest()->limit(10)->with(['transactions', 'vendorPayments']);
                },
                'onlinePharmacyPurchases' => function ($query) {
                    $query->latest()->limit(10)->with(['transactions', 'vendorPayments']);
                },
                'medicalAttentionSubscriptions' => function ($query) {
                    $query->latest()->limit(10)->with(['transactions']);
                },
            ])
            ->first();

        $efevooTokens = collect();
        $efevooTransactions = collect();

        if ($customer) {
            $efevooTokens = EfevooToken::byCustomer($customer->id)
                ->withCount('transactions')
                ->get();

            if ($efevooTokens->isNotEmpty()) {
                $efevooTransactions = EfevooTransaction::whereIn('efevoo_token_id', $efevooTokens->pluck('id'))
                    ->latest()
                    ->limit(20)
                    ->get();
            }
        }

        // Notificaciones: muchas llegan ligadas a la compra (laboratory_purchase_id) y pueden no traer user_id.
        // Para el detalle de usuario, traemos por:
        // - user_id = user.id
        // - email_recipient_id = user.id
        // - laboratoryPurchase.customer_id = customer.id (si existe)
        $labNotificationsQuery = LaboratoryNotification::query()
            ->with(['laboratoryPurchase', 'laboratoryQuote'])
            ->where(function ($q) use ($user, $customer) {
                $q->where('user_id', $user->id)
                    ->orWhere('email_recipient_id', $user->id);

                if ($customer) {
                    $q->orWhereHas('laboratoryPurchase', function ($p) use ($customer) {
                        $p->where('customer_id', $customer->id);
                    });
                }
            })
            ->latest();

        $labNotifications = $labNotificationsQuery->limit(25)->get();
        $unreadLabNotificationsCount = (clone $labNotificationsQuery)->whereNull('read_at')->count();

        $monitoringCarts = null;
        $canViewCartDetails = request()->user()->administrator->hasPermissionTo('view cart details');
        if (request()->user()->administrator->hasPermissionTo('view carts')) {
            $monitoringCarts = Cart::query()
                ->with('items')
                ->where('user_id', $user->id)
                ->orderByDesc('updated_at')
                ->get()
                ->map(function (Cart $cart) {
                    return [
                        'id' => $cart->id,
                        'type_label' => $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
                        'display_status' => $cart->displayStatus(),
                        'items_count' => $cart->items->count(),
                        'total_formatted' => formattedPrice((float) $cart->total),
                    ];
                })
                ->values()
                ->all();
        }

        return Inertia::render('Admin/User', [
            'user' => $user,
            'genders' => Gender::casesWithLabels(),
            'states' => StatesMexico::todos(),
            'customer' => $customer,
            'canViewTaxProfilesAdmin' => request()->user()->administrator->hasPermissionTo('tax-profiles.manage'),
            'canUpdatePassword' => (bool) request()->user()->administrator?->hasRole('superadmin'),
            'efevooTokens' => $efevooTokens,
            'efevooTransactions' => $efevooTransactions,
            'laboratoryNotifications' => $labNotifications,
            'unreadLabNotificationsCount' => $unreadLabNotificationsCount,
            'monitoringCarts' => $monitoringCarts,
            'canViewCartDetails' => $canViewCartDetails,
            'activeCampaignTimeline' => $this->activeCampaignTimeline($user, $customer),
        ]);
    }

    private function activeCampaignTimeline(User $user, ?Customer $customer): array
    {
        if (! Schema::hasTable('activecampaign_dispatches')) {
            return [
                'available' => false,
                'items' => [],
                'summary' => [
                    'total' => 0,
                    'tag_adds' => 0,
                    'tag_removes' => 0,
                    'events' => 0,
                    'fields' => 0,
                ],
            ];
        }

        $customerId = $customer?->id;
        $email = trim((string) $user->email);

        $dispatches = ActiveCampaignDispatch::query()
            ->where(function ($query) use ($user, $customerId, $email) {
                $query->where('user_id', $user->id);

                if ($customerId !== null) {
                    $query->orWhere('customer_id', $customerId);
                }

                if ($email !== '') {
                    $query->orWhere('email', $email);
                }
            })
            ->where(function ($query) {
                $query->whereIn('payload->operation', [
                    'tag_add',
                    'tag_remove',
                    'site_event',
                    'lab_custom_fields',
                    'laboratory_purchase_completed',
                ])
                    ->orWhereNotNull('payload->tags');
            })
            ->latest('updated_at')
            ->latest('id')
            ->limit(40)
            ->get();

        $items = $dispatches
            ->flatMap(fn (ActiveCampaignDispatch $dispatch) => $this->activeCampaignTimelineItems($dispatch))
            ->values()
            ->all();

        return [
            'available' => true,
            'items' => $items,
            'summary' => [
                'total' => count($items),
                'tag_adds' => collect($items)->where('kind', 'tag_add')->count(),
                'tag_removes' => collect($items)->where('kind', 'tag_remove')->count(),
                'events' => collect($items)->where('kind', 'site_event')->count(),
                'fields' => collect($items)->where('kind', 'fields')->count(),
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function activeCampaignTimelineItems(ActiveCampaignDispatch $dispatch)
    {
        $payload = is_array($dispatch->payload) ? $dispatch->payload : [];
        $operation = (string) ($payload['operation'] ?? '');
        $items = collect();

        if (in_array($operation, ['tag_add', 'tag_remove'], true)) {
            $items->push($this->activeCampaignTagTimelineItem(
                dispatch: $dispatch,
                payload: $payload,
                kind: $operation,
                tagKey: $payload['tag_key'] ?? null,
                tagName: $payload['tag_name'] ?? null,
                tagId: $payload['tag_id'] ?? null,
            ));
        }

        if ($operation === 'laboratory_purchase_completed') {
            $items->push($this->activeCampaignBaseTimelineItem($dispatch, $payload, [
                'kind' => 'purchase',
                'title' => 'Compra lab completada',
                'label' => 'Compra laboratorio #'.($payload['laboratory_purchase_id'] ?? $dispatch->entity_id),
                'description' => implode(' · ', array_filter([
                    $payload['brand'] ?? null,
                    isset($payload['total_cents'])
                        ? '$'.number_format(((int) $payload['total_cents']) / 100, 2)
                        : null,
                    $payload['gda_order_id'] ?? null,
                ])),
            ]));

            foreach ($this->laboratoryPurchaseTagsForTimeline($dispatch, $payload) as $tag) {
                if (! is_array($tag)) {
                    continue;
                }

                $items->push($this->activeCampaignTagTimelineItem(
                    dispatch: $dispatch,
                    payload: $payload,
                    kind: 'tag_add',
                    tagKey: $tag['key'] ?? null,
                    tagName: $tag['name'] ?? null,
                    tagId: $tag['id'] ?? null,
                    label: 'Compra lab confirmada',
                ));
            }

            $fields = collect($payload['custom_fields'] ?? [])
                ->keys()
                ->implode(', ');

            if ($fields !== '') {
                $items->push($this->activeCampaignBaseTimelineItem($dispatch, $payload, [
                    'kind' => 'fields',
                    'title' => 'Campos de compra lab enviados',
                    'label' => $fields,
                    'description' => 'laboratory_purchase_completed',
                ]));
            }
        }

        if ($operation === 'site_event') {
            $items->push($this->activeCampaignBaseTimelineItem($dispatch, $payload, [
                'kind' => 'site_event',
                'title' => 'Evento enviado',
                'label' => (string) ($payload['event_name'] ?? $dispatch->event_type),
                'description' => (string) ($payload['source_event_type'] ?? $dispatch->event_type),
            ]));
        }

        if ($operation === 'lab_custom_fields') {
            $fields = collect($payload['custom_fields'] ?? [])
                ->keys()
                ->implode(', ');

            $items->push($this->activeCampaignBaseTimelineItem($dispatch, $payload, [
                'kind' => 'fields',
                'title' => 'Campos actualizados',
                'label' => $fields !== '' ? $fields : 'Campos laboratorio',
                'description' => (string) ($payload['event_type'] ?? $dispatch->event_type),
            ]));
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function laboratoryPurchaseTagsForTimeline(ActiveCampaignDispatch $dispatch, array $payload): array
    {
        $tags = $payload['tags'] ?? [];

        if (is_array($tags) && $tags !== []) {
            return $tags;
        }

        $purchaseId = (int) ($payload['laboratory_purchase_id'] ?? $dispatch->entity_id ?? 0);
        $purchase = $purchaseId > 0
            ? LaboratoryPurchase::query()->with('laboratoryAppointment')->find($purchaseId)
            : null;

        $inferred = [];
        $completedId = (int) config('services.activecampaign.tag_laboratory_purchase_completed', 18);

        if ($completedId > 0) {
            $inferred[] = [
                'key' => 'tag_laboratory_purchase_completed',
                'id' => $completedId,
                'name' => null,
            ];
        }

        $modeKey = $purchase?->laboratoryAppointment
            ? 'tag_laboratory_purchase_with_appointment'
            : 'tag_laboratory_purchase_without_appointment';
        $modeName = config('services.activecampaign.'.$modeKey);

        if (is_string($modeName) && trim($modeName) !== '' && trim($modeName) !== '0') {
            $inferred[] = [
                'key' => $modeKey,
                'id' => null,
                'name' => trim($modeName),
            ];
        }

        return $inferred;
    }

    private function activeCampaignTagTimelineItem(
        ActiveCampaignDispatch $dispatch,
        array $payload,
        string $kind,
        mixed $tagKey,
        mixed $tagName,
        mixed $tagId,
        ?string $label = null,
    ): array {
        return $this->activeCampaignBaseTimelineItem($dispatch, $payload, [
            'kind' => $kind,
            'title' => $kind === 'tag_remove' ? 'Etiqueta removida' : 'Etiqueta agregada',
            'label' => $label ?: ((string) ($tagName ?: $tagKey ?: 'Etiqueta ActiveCampaign')),
            'description' => implode(' · ', array_filter([
                $tagKey ? 'key: '.$tagKey : null,
                $tagId ? 'ID: '.$tagId : null,
            ])),
        ]);
    }

    private function activeCampaignBaseTimelineItem(ActiveCampaignDispatch $dispatch, array $payload, array $extra): array
    {
        return array_merge([
            'id' => $dispatch->id.'-'.($extra['kind'] ?? 'item').'-'.md5(json_encode($extra)),
            'dispatch_id' => $dispatch->id,
            'status' => $dispatch->status,
            'status_label' => $this->activeCampaignStatusLabel($dispatch->status),
            'event_type' => $dispatch->event_type,
            'operation' => $payload['operation'] ?? null,
            'idempotency_key' => $dispatch->idempotency_key,
            'created_at' => optional($dispatch->created_at)->toIso8601String(),
            'updated_at' => optional($dispatch->updated_at)->toIso8601String(),
            'synced_at' => optional($dispatch->synced_at)->toIso8601String(),
            'last_error' => $dispatch->last_error,
            'entity_type' => $dispatch->entity_type,
            'entity_id' => $dispatch->entity_id,
            'related_entity_type' => $dispatch->related_entity_type,
            'related_entity_id' => $dispatch->related_entity_id,
            'cart_id' => $payload['cart_id'] ?? null,
            'purchase_id' => $payload['laboratory_purchase_id'] ?? $payload['purchase_id'] ?? null,
            'appointment_id' => $payload['appointment_id'] ?? null,
            'jobs_url' => route('admin.activecampaign-jobs.index', ['dispatch_id' => $dispatch->id], absolute: false),
        ], $extra);
    }

    private function activeCampaignStatusLabel(string $status): string
    {
        return match ($status) {
            ActiveCampaignDispatch::STATUS_SYNCED => 'Sincronizado',
            ActiveCampaignDispatch::STATUS_FAILED => 'Error',
            ActiveCampaignDispatch::STATUS_PROCESSING => 'Procesando',
            ActiveCampaignDispatch::STATUS_PENDING => 'Pendiente',
            ActiveCampaignDispatch::STATUS_SKIPPED => 'Omitido',
            default => $status,
        };
    }

    public function update(UpdateUserRequest $request, User $user, UpdateAdminUserAction $updateAdminUserAction)
    {
        $updateAdminUserAction($user, $request->validated());

        return back()->flashMessage('Usuario actualizado correctamente.');
    }

    public function verifyEmail(User $user, CouponBeneficiaryService $beneficiaryService)
    {
        request()->user()->administrator->hasPermissionTo('users.manage') || abort(403);

        $user->email_verified_at = now();
        $user->save();

        $beneficiaryService->linkPendingBeneficiariesForUser($user->fresh());

        return back()->flashMessage('Correo marcado como verificado.');
    }

    public function verifyPhone(User $user)
    {
        request()->user()->administrator->hasPermissionTo('users.manage') || abort(403);

        $user->phone_verified_at = now();
        $user->save();

        return back()->flashMessage('Teléfono marcado como verificado.');
    }

    public function updatePassword(Request $request, User $user)
    {
        $admin = $request->user()->administrator;

        abort_unless($admin && $admin->hasRole('superadmin'), 403);

        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->password = Hash::make($request->password);
        $user->save();

        return back()->flashMessage('Contraseña actualizada correctamente.');
    }
}
