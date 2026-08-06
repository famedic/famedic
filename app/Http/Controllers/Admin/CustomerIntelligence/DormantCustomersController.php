<?php

namespace App\Http\Controllers\Admin\CustomerIntelligence;

use App\Data\StatesMexico;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerIntelligence\IndexDormantCustomersRequest;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Services\CustomerIntelligence\DormantCustomersAnalyticsService;
use App\Services\CustomerIntelligence\DormantCustomersRepository;
use App\Support\CustomerIntelligence\DormantCustomersFilter;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DormantCustomersController extends Controller
{
    public function __construct(
        private DormantCustomersAnalyticsService $analytics,
        private DormantCustomersRepository $repository,
    ) {
    }

    public function index(IndexDormantCustomersRequest $request): Response|StreamedResponse|HttpResponse
    {
        $filter = DormantCustomersFilter::fromRequest($request);

        if ($request->filled('export')) {
            return $this->export($filter, $request->string('export')->toString());
        }

        $only = collect(explode(',', (string) $request->header('X-Inertia-Partial-Data')))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->values();

        $drawerOnly = $only->count() === 1 && $only->first() === 'drawer';

        $drawer = null;
        if ($request->filled('drawer_customer_id')) {
            $customer = Customer::query()->find($request->integer('drawer_customer_id'));
            if ($customer) {
                $drawer = $this->repository->customerDrawer($customer);
            }
        }

        if ($drawerOnly) {
            return Inertia::render('Admin/CustomerIntelligence/DormantCustomers', [
                'drawer' => $drawer,
            ]);
        }

        $dashboard = $this->analytics->build($filter);
        $customers = $this->repository->paginateDormant($filter);

        return Inertia::render('Admin/CustomerIntelligence/DormantCustomers', [
            'filters' => $filter->toArray(),
            'filterOptions' => [
                'states' => $this->analytics->stateOptions(),
                'cities' => $this->analytics->cityOptions(),
                'sources' => [
                    ['value' => 'organico', 'label' => 'Orgánico / Web'],
                    ['value' => 'referred', 'label' => 'Referidos'],
                    ['value' => 'odessa', 'label' => 'Odessa'],
                    ['value' => 'familiar', 'label' => 'Familiar'],
                ],
                'account_types' => [
                    ['value' => 'regular', 'label' => 'Regular'],
                    ['value' => 'odessa', 'label' => 'Odessa'],
                    ['value' => 'familiar', 'label' => 'Familiar'],
                ],
                'days_buckets' => [
                    ['value' => '0-7', 'label' => '0–7 días'],
                    ['value' => '8-30', 'label' => '8–30 días'],
                    ['value' => '31-60', 'label' => '31–60 días'],
                    ['value' => '61-90', 'label' => '61–90 días'],
                    ['value' => '90+', 'label' => '90+ días'],
                ],
            ],
            'kpis' => $dashboard['kpis'],
            'evolution' => $dashboard['evolution'],
            'daysBuckets' => $dashboard['days_buckets'],
            'bySource' => $dashboard['by_source'],
            'funnel' => $dashboard['funnel'],
            'byState' => $dashboard['by_state'],
            'byCity' => $dashboard['by_city'],
            'segments' => $dashboard['segments'],
            'sourceConversion' => $dashboard['source_conversion'],
            'marketingIntelligence' => $dashboard['marketing_intelligence'],
            'aiInsights' => $dashboard['ai_insights'],
            'automations' => $dashboard['automations'],
            'advancedMetrics' => $dashboard['advanced_metrics'],
            'customers' => $customers,
            'drawer' => $drawer,
            'meta' => $dashboard['meta'],
            'customersIndexUrl' => route('admin.customers.index'),
            'canExport' => $request->user()->administrator->hasPermissionTo('customers.manage.export'),
        ]);
    }

    private function export(DormantCustomersFilter $filter, string $format): StreamedResponse|HttpResponse
    {
        $filename = 'clientes-dormidos-'.now('America/Monterrey')->format('Y-m-d-His');

        if ($format === 'pdf') {
            return response(
                "Exportación PDF de Clientes Dormidos — {$filename}\n".
                "Pendiente de plantilla PDF. Usa CSV/Excel mientras tanto.\n",
                200,
                [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                    'Content-Disposition' => "attachment; filename=\"{$filename}.txt\"",
                ]
            );
        }

        $delimiter = $format === 'csv' ? ',' : "\t";
        $extension = $format === 'csv' ? 'csv' : 'xls';

        return response()->streamDownload(function () use ($filter, $delimiter) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($out, [
                'ID',
                'Nombre',
                'Email',
                'Teléfono',
                'Ciudad',
                'Estado',
                'Fecha registro',
                'Días sin comprar',
                'Fuente',
                'Ítems lab carrito',
                'Ítems farmacia carrito',
                'Checkouts',
            ], $delimiter);

            $this->repository->dormantBaseQuery($filter)
                ->with(['user', 'customerable', 'addresses'])
                ->withCount(['laboratoryCartItems', 'onlinePharmacyCartItems', 'laboratoryCheckoutDrafts'])
                ->orderBy('customers.id')
                ->chunk(200, function ($chunk) use ($out, $delimiter) {
                    foreach ($chunk as $customer) {
                        $days = $customer->created_at ? (int) $customer->created_at->diffInDays(now()) : 0;
                        $address = $customer->addresses->first();

                        if ($customer->customerable_type === FamilyAccount::class) {
                            $name = $customer->customerable?->full_name
                                ?? trim(($customer->customerable?->name ?? '').' '.($customer->customerable?->paternal_lastname ?? ''))
                                ?: 'Familiar #'.$customer->id;
                        } else {
                            $name = $customer->user?->full_name
                                ?? trim(($customer->user?->name ?? '').' '.($customer->user?->paternal_lastname ?? ''))
                                ?: 'Cliente #'.$customer->id;
                        }

                        $source = match (true) {
                            (bool) $customer->user?->referred_by => 'Referidos',
                            $customer->customerable_type === OdessaAfiliateAccount::class => 'Odessa',
                            $customer->customerable_type === FamilyAccount::class => 'Familiar',
                            default => 'Orgánico / Web',
                        };

                        fputcsv($out, [
                            $customer->id,
                            $name,
                            $customer->user?->email,
                            $customer->user?->full_phone,
                            $address?->city,
                            $customer->user?->state
                                ? (StatesMexico::obtenerNombre($customer->user->state) ?? $customer->user->state)
                                : null,
                            $customer->created_at?->timezone('America/Monterrey')->toDateTimeString(),
                            $days,
                            $source,
                            $customer->laboratory_cart_items_count,
                            $customer->online_pharmacy_cart_items_count,
                            $customer->laboratory_checkout_drafts_count,
                        ], $delimiter);
                    }
                });

            fclose($out);
        }, "{$filename}.{$extension}", [
            'Content-Type' => $format === 'csv'
                ? 'text/csv; charset=UTF-8'
                : 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }
}
