<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customers\IndexCustomerReferralRequest;
use App\Models\User;
use App\Services\CustomerIntelligence\ReferralIntelligenceAnalyticsService;
use App\Services\CustomerIntelligence\ReferralIntelligenceRepository;
use App\Support\CustomerIntelligence\ReferralIntelligenceFilter;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerReferralController extends Controller
{
    public function __construct(
        private ReferralIntelligenceAnalyticsService $analytics,
        private ReferralIntelligenceRepository $repository,
    ) {
    }

    public function index(IndexCustomerReferralRequest $request): Response|StreamedResponse|HttpResponse
    {
        $filter = ReferralIntelligenceFilter::fromRequest($request);

        if ($request->filled('export')) {
            return $this->export($filter);
        }

        $only = collect(explode(',', (string) $request->header('X-Inertia-Partial-Data')))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->values();

        $drawerOnly = $only->count() === 1 && $only->first() === 'drawer';

        $drawer = null;
        if ($filter->drawerUserId) {
            $user = User::query()->find($filter->drawerUserId);
            if ($user) {
                $drawer = $this->repository->inviterDrawer($user, $filter);
            }
        }

        if ($drawerOnly) {
            return Inertia::render('Admin/CustomerReferrals', [
                'drawer' => $drawer,
            ]);
        }

        $payload = $this->analytics->build($filter);
        $inviters = $this->repository->paginateInviters($filter);

        return Inertia::render('Admin/CustomerReferrals', [
            'filters' => $filter->toArray(),
            'filterOptions' => $this->repository->filterOptions(),
            'kpis' => $payload['kpis'],
            'evolution' => $payload['evolution'],
            'topInviters' => $payload['top_inviters'],
            'statusBreakdown' => $payload['status_breakdown'],
            'leaderboards' => $payload['leaderboards'],
            'marketingInsights' => $payload['marketing_insights'],
            'aiInsights' => $payload['ai_insights'],
            'automations' => $payload['automations'],
            'performance' => $payload['performance'],
            'compare' => $payload['compare'],
            'meta' => $payload['meta'],
            'inviters' => $inviters,
            'drawer' => $drawer,
            'customersIndexUrl' => route('admin.customers.index'),
            'hubUrl' => route('admin.customer-intelligence.index'),
            'canExport' => true,
        ]);
    }

    private function export(ReferralIntelligenceFilter $filter): StreamedResponse
    {
        $rows = $this->repository->topInviters($filter, 500);
        $filename = 'referral-intelligence-'.now('America/Monterrey')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Invitador',
                'Email',
                'Empresa',
                'Referidos',
                'Compradores',
                'Conversion %',
                'Ingresos MXN',
                'Creditos MXN',
                'Nivel',
                'Ultima invitacion',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $row['company'] ?? '',
                    $row['referrals'] ?? 0,
                    $row['buyers'] ?? 0,
                    $row['conversion'] ?? 0,
                    round(($row['revenue_cents'] ?? 0) / 100, 2),
                    round(($row['credits_cents'] ?? 0) / 100, 2),
                    $row['level']['label'] ?? '',
                    $row['last_referral_at'] ?? '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
