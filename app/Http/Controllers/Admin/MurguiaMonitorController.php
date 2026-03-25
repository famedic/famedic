<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\MurguiaMonitorSingleCustomerAction;
use App\Actions\MedicalAttention\CheckStatusAction;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessMurguiaExcelJob;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\MurguiaSyncLog;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use App\Enums\MedicalSubscriptionType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MurguiaMonitorController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only([
            'search',
            'account_type',
            'local_status',
            'subscription_type',
            'murguia_sync',
        ]);

        $query = Customer::query()
            ->with(['user', 'customerable'])
            ->withCount('laboratoryPurchases')
            ->with(['medicalAttentionSubscriptions' => function ($q) {
                $q->orderByDesc('end_date');
            }]);

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('medical_attention_identifier', 'like', '%' . $s . '%')
                    ->orWhereHas('user', function ($q) use ($s) {
                        $q->where('email', 'like', '%' . $s . '%')
                            ->orWhere('name', 'like', '%' . $s . '%')
                            ->orWhere('paternal_lastname', 'like', '%' . $s . '%')
                            ->orWhere('maternal_lastname', 'like', '%' . $s . '%');
                    });
            });
        }

        if (! empty($filters['account_type'])) {
            $map = [
                'odessa' => OdessaAfiliateAccount::class,
                'regular' => RegularAccount::class,
                'familiar' => FamilyAccount::class,
            ];
            if (isset($map[$filters['account_type']])) {
                $query->where('customerable_type', $map[$filters['account_type']]);
            }
        }

        if (! empty($filters['local_status'])) {
            if ($filters['local_status'] === 'active') {
                $query->where('medical_attention_subscription_expires_at', '>', now());
            } elseif ($filters['local_status'] === 'inactive') {
                $query->where(function ($q) {
                    $q->whereNull('medical_attention_subscription_expires_at')
                        ->orWhere('medical_attention_subscription_expires_at', '<=', now());
                });
            }
        }

        if (! empty($filters['subscription_type'])) {
            if ($filters['subscription_type'] === 'none') {
                $query->whereDoesntHave('medicalAttentionSubscriptions');
            } else {
                $query->whereHas('medicalAttentionSubscriptions', function ($q) use ($filters) {
                    $type = $filters['subscription_type'];
                    if ($type === 'trial') {
                        $q->where('type', MedicalSubscriptionType::TRIAL);
                    } elseif ($type === 'regular') {
                        $q->where('type', MedicalSubscriptionType::REGULAR);
                    } elseif ($type === 'institutional') {
                        $q->where('type', MedicalSubscriptionType::INSTITUTIONAL);
                    } elseif ($type === 'family_member') {
                        $q->where('type', MedicalSubscriptionType::FAMILY_MEMBER);
                    }
                });
            }
        }

        if (! empty($filters['murguia_sync'])) {
            if ($filters['murguia_sync'] === 'never') {
                $query->whereDoesntHave('medicalAttentionSubscriptions', function ($q) {
                    $q->whereNotNull('synced_with_murguia_at');
                });
            } elseif ($filters['murguia_sync'] === 'synced') {
                $query->whereHas('medicalAttentionSubscriptions', function ($q) {
                    $q->whereNotNull('synced_with_murguia_at');
                });
            }
        }

        $paginator = $query
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $paginator->getCollection()->transform(function (Customer $c) {
            $subs = $c->medicalAttentionSubscriptions;
            $sub = $subs->first();

            $lastSynced = $subs->filter(fn ($s) => $s->synced_with_murguia_at !== null)
                ->sortByDesc(fn ($s) => $s->synced_with_murguia_at)
                ->first();

            $hasMurguiaSync = $subs->contains(fn ($s) => $s->synced_with_murguia_at !== null);

            $subscriptionTypeLabel = 'none';
            if ($sub) {
                $subscriptionTypeLabel = match ($sub->type) {
                    MedicalSubscriptionType::TRIAL => 'trial',
                    MedicalSubscriptionType::REGULAR => 'regular',
                    MedicalSubscriptionType::INSTITUTIONAL => 'institutional',
                    MedicalSubscriptionType::FAMILY_MEMBER => 'family_member',
                    default => $sub->type->value,
                };
            }

            return [
                'id' => $c->id,
                'name' => $c->user?->full_name ?? '—',
                'email' => $c->user?->email,
                'phone' => $c->user?->full_phone,
                'medical_attention_identifier' => $c->medical_attention_identifier,
                'account_type' => match ($c->customerable_type) {
                    OdessaAfiliateAccount::class => 'odessa',
                    FamilyAccount::class => 'familiar',
                    default => 'regular',
                },
                'subscription_type' => $subscriptionTypeLabel,
                'local_status' => $c->medical_attention_subscription_is_active ? 'active' : 'inactive',
                'expires_at' => $c->medical_attention_subscription_expires_at?->toIso8601String(),
                'laboratory_purchases_count' => $c->laboratory_purchases_count,
                'has_murguia_sync' => $hasMurguiaSync,
                'last_synced_murguia_at' => $lastSynced?->synced_with_murguia_at?->toIso8601String(),
            ];
        });

        $customers = $paginator;

        $stats = [
            'total_local_active' => Customer::where('medical_attention_subscription_expires_at', '>', now())->count(),
            'total_local_inactive' => Customer::where(function ($q) {
                $q->whereNull('medical_attention_subscription_expires_at')
                    ->orWhere('medical_attention_subscription_expires_at', '<=', now());
            })->count(),
            'total_subscription_active' => Customer::where('medical_attention_subscription_expires_at', '>', now())->count(),
            'total_no_lab_usage' => Customer::query()
                ->whereDoesntHave('laboratoryPurchases')
                ->count(),
        ];

        return Inertia::render('Admin/MurguiaMonitor', [
            'customers' => $customers,
            'filters' => $filters,
            'stats' => $stats,
            'murguiaCheck' => $request->session()->pull('murguia_check'),
            'successMessage' => $request->session()->pull('success'),
            'errorMessage' => $request->session()->pull('error'),
        ]);
    }

    public function show(Request $request, Customer $customer): Response
    {
        $customer->load(['user', 'customerable', 'medicalAttentionSubscriptions' => function ($q) {
            $q->orderByDesc('end_date');
        }]);

        $logs = MurguiaSyncLog::query()
            ->where('customer_id', $customer->id)
            ->with('triggeredBy')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (MurguiaSyncLog $l) => [
                'id' => $l->id,
                'created_at' => $l->created_at,
                'action' => $l->action,
                'status' => $l->status,
                'message' => $l->message,
                'entry_type' => $l->entry_type,
                'admin_email' => $l->triggeredBy?->email,
            ]);

        return Inertia::render('Admin/MurguiaMonitorShow', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->user?->full_name,
                'email' => $customer->user?->email,
                'phone' => $customer->user?->full_phone,
                'medical_attention_identifier' => $customer->medical_attention_identifier,
                'account_type' => match ($customer->customerable_type) {
                    OdessaAfiliateAccount::class => 'odessa',
                    FamilyAccount::class => 'familiar',
                    default => 'regular',
                },
                'local_status' => $customer->medical_attention_subscription_is_active ? 'active' : 'inactive',
                'expires_at' => $customer->medical_attention_subscription_expires_at?->toIso8601String(),
                'subscriptions' => $customer->medicalAttentionSubscriptions->map(fn ($s) => [
                    'id' => $s->id,
                    'type' => $s->type->value,
                    'start_date' => $s->start_date?->toDateString(),
                    'end_date' => $s->end_date?->toDateString(),
                    'synced_with_murguia_at' => $s->synced_with_murguia_at?->toIso8601String(),
                ]),
            ],
            'syncLogs' => $logs,
            'murguiaCheck' => $request->session()->pull('murguia_check'),
        ]);
    }

    public function uploadPage(Request $request): Response
    {
        return Inertia::render('Admin/MurguiaUpload', [
            'successMessage' => $request->session()->pull('success'),
        ]);
    }

    public function checkStatus(Request $request, Customer $customer, CheckStatusAction $checkStatusAction): RedirectResponse
    {
        $response = $checkStatusAction($customer);
        $body = $response->json() ?? [];

        MurguiaSyncLog::create([
            'customer_id' => $customer->id,
            'triggered_by' => $request->user()?->id,
            'email' => $customer->user?->email,
            'medical_attention_identifier' => $customer->medical_attention_identifier,
            'action' => MurguiaSyncLog::ACTION_VALIDACION,
            'request_payload' => ['noCredito' => (string) $customer->medical_attention_identifier],
            'response_payload' => array_merge($body, ['http_status' => $response->status()]),
            'status' => $response->successful() ? MurguiaSyncLog::STATUS_SUCCESS : MurguiaSyncLog::STATUS_FAILED,
            'message' => 'Consulta manual de estatus Murguía',
            'entry_type' => MurguiaSyncLog::ENTRY_TYPE_SINGLE,
        ]);

        return redirect()
            ->back()
            ->with('murguia_check', [
                'http' => $response->status(),
                'body' => $body,
            ]);
    }

    public function activateCustomer(Request $request, Customer $customer, MurguiaMonitorSingleCustomerAction $action): RedirectResponse
    {
        $result = $action($customer->id, 'activate', $request->user()?->id);

        return redirect()
            ->back()
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function deactivateCustomer(Request $request, Customer $customer, MurguiaMonitorSingleCustomerAction $action): RedirectResponse
    {
        $result = $action($customer->id, 'deactivate', $request->user()?->id);

        return redirect()
            ->back()
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function uploadExcel(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv'],
        ]);

        $path = $request->file('file')->store('murguia-uploads', 'local');

        ProcessMurguiaExcelJob::dispatch('local', $path);

        return redirect()
            ->route('admin.murguia.upload')
            ->with('success', 'Archivo recibido. Las filas se procesarán en segundo plano.');
    }

    public function logs(Request $request): Response
    {
        $logs = MurguiaSyncLog::query()
            ->with(['customer.user', 'triggeredBy'])
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(function (MurguiaSyncLog $log) {
                return [
                    'id' => $log->id,
                    'created_at' => $log->created_at,
                    'entry_type' => $log->entry_type,
                    'action' => $log->action,
                    'status' => $log->status,
                    'email' => $log->email,
                    'medical_attention_identifier' => $log->medical_attention_identifier,
                    'customer_id' => $log->customer_id,
                    'message' => $log->message,
                    'admin_email' => $log->triggeredBy?->email,
                ];
            });

        return Inertia::render('Admin/MurguiaLogs', [
            'logs' => $logs,
        ]);
    }
}
