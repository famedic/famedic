<?php

namespace App\Http\Controllers\Admin\CustomerIntelligence;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerIntelligenceHubController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->user()->can('viewAny', Customer::class) || abort(403);

        $totalCustomers = $this->safeCount(fn () => Customer::query()->count());

        $modules = [
            [
                'id' => 'dashboard',
                'title' => 'Dashboard',
                'description' => 'Ver KPIs generales',
                'href' => route('admin.customer-intelligence.index'),
                'icon' => 'dashboard',
                'status' => 'active',
                'count_label' => 'Clientes',
                'count' => $totalCustomers,
                'accent' => 'indigo',
                'is_hub' => true,
            ],
            [
                'id' => 'dormant',
                'title' => 'Clientes Dormidos',
                'description' => 'Clientes registrados sin compras',
                'href' => route('admin.customers.dormant'),
                'icon' => 'dormant',
                'status' => 'active',
                'count_label' => 'Dormidos',
                'count' => $this->safeCount(fn () => Customer::query()
                    ->whereDoesntHave('laboratoryPurchases')
                    ->whereDoesntHave('onlinePharmacyPurchases')
                    ->whereDoesntHave('medicalAttentionSubscriptions')
                    ->count()),
                'accent' => 'orange',
            ],
            [
                'id' => 'journey',
                'title' => 'Customer Journey',
                'description' => 'Recorrido completo',
                'href' => route('admin.customer-intelligence.customer-journey'),
                'icon' => 'journey',
                'status' => 'active',
                'count_label' => 'Registros 90d',
                'count' => $this->safeCount(fn () => Customer::query()
                    ->where('created_at', '>=', now()->subDays(90))
                    ->count()),
                'accent' => 'sky',
            ],
            [
                'id' => 'cohorts',
                'title' => 'Cohorts & Retention',
                'description' => 'Retención',
                'href' => route('admin.customer-intelligence.cohorts'),
                'icon' => 'cohorts',
                'status' => 'active',
                'count_label' => 'Módulos',
                'count' => null,
                'accent' => 'purple',
            ],
            [
                'id' => 'health',
                'title' => 'Customer Health',
                'description' => 'Health Score',
                'href' => route('admin.customer-intelligence.customer-health'),
                'icon' => 'health',
                'status' => 'active',
                'count_label' => 'Health',
                'count' => null,
                'accent' => 'green',
            ],
            [
                'id' => 'referrals',
                'title' => 'Referral Intelligence',
                'description' => 'Desempeño del programa de referidos',
                'href' => route('admin.customers.referrals'),
                'icon' => 'referrals',
                'status' => 'active',
                'count_label' => 'Invitadores',
                'count' => $this->safeCount(fn () => \App\Models\User::query()->whereHas('referrals')->count()),
                'accent' => 'orange',
            ],
            [
                'id' => 'marketing-intelligence',
                'title' => 'Marketing Intelligence',
                'description' => 'Próximamente — orquestación comercial cross-channel',
                'href' => null,
                'icon' => 'marketing',
                'status' => 'coming_soon',
                'count_label' => null,
                'count' => null,
                'accent' => 'slate',
            ],
            [
                'id' => 'ai-insights',
                'title' => 'AI Insights',
                'description' => 'Próximamente — hallazgos y recomendaciones ejecutivas',
                'href' => null,
                'icon' => 'ai',
                'status' => 'coming_soon',
                'count_label' => null,
                'count' => null,
                'accent' => 'violet',
            ],
        ];

        return Inertia::render('Admin/CustomerIntelligence/Hub', [
            'modules' => $modules,
            'meta' => [
                'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                'title' => 'Customer Intelligence Center',
                'subtitle' => 'Inteligencia comercial para Marketing, Growth y Dirección.',
            ],
        ]);
    }

    private function safeCount(callable $callback): ?int
    {
        try {
            return (int) $callback();
        } catch (\Throwable) {
            return null;
        }
    }
}
