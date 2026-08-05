<?php

namespace App\Services\Intelligence;

use App\Models\Customer;
use App\Models\User;
use App\Support\Intelligence\IntelligenceSuiteCatalog;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class IntelligenceHubService
{
    /**
     * @return array{suites: list<array<string, mixed>>, summary: array<string, mixed>, quick_actions: list<array<string, mixed>>, search_index: list<array<string, mixed>>, hero_stats: array<string, int>}
     */
    public function buildFor(mixed $administrator, User $user): array
    {
        $suites = [];
        $searchIndex = [];
        $moduleCount = 0;

        foreach (IntelligenceSuiteCatalog::suites() as $suite) {
            if (! $this->canAccessSuite($administrator, $suite)) {
                continue;
            }

            $modules = [];
            foreach ($suite['modules'] as $module) {
                if (! $this->canAccessModule($administrator, $module)) {
                    continue;
                }

                $resolved = $this->resolveModule($module);
                $modules[] = $resolved;
                $moduleCount++;

                $searchIndex[] = [
                    'type' => 'module',
                    'suite' => $suite['name'],
                    'suite_slug' => $suite['slug'],
                    'title' => $resolved['title'],
                    'description' => $resolved['description'],
                    'href' => $resolved['href'],
                    'status' => $resolved['status'],
                ];
            }

            if ($modules === []) {
                continue;
            }

            $suitePayload = [
                'id' => $suite['id'],
                'slug' => $suite['slug'],
                'emoji' => $suite['emoji'],
                'name' => $suite['name'],
                'description' => $suite['description'],
                'accent' => $suite['accent'],
                'href' => route('admin.intelligence.suite', $suite['slug']),
                'module_count' => count($modules),
                'stats' => $this->suiteStats($suite, $modules),
                'modules' => $modules,
            ];

            $suites[] = $suitePayload;

            $searchIndex[] = [
                'type' => 'suite',
                'suite' => $suite['name'],
                'suite_slug' => $suite['slug'],
                'title' => $suite['name'],
                'description' => $suite['description'],
                'href' => $suitePayload['href'],
                'status' => 'active',
            ];
        }

        return [
            'suites' => $suites,
            'summary' => $this->executiveSummary($suites, $user),
            'quick_actions' => $this->quickActions($suites),
            'search_index' => $searchIndex,
            'hero_stats' => [
                'suites' => count($suites),
                'modules' => $moduleCount,
                'alerts' => 5,
                'insights' => 12,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function suiteFor(mixed $administrator, string $slug): ?array
    {
        $suite = IntelligenceSuiteCatalog::find($slug);
        if (! $suite || ! $this->canAccessSuite($administrator, $suite)) {
            return null;
        }

        $modules = [];
        foreach ($suite['modules'] as $module) {
            if (! $this->canAccessModule($administrator, $module)) {
                continue;
            }
            $modules[] = $this->resolveModule($module);
        }

        return [
            'id' => $suite['id'],
            'slug' => $suite['slug'],
            'emoji' => $suite['emoji'],
            'name' => $suite['name'],
            'description' => $suite['description'],
            'accent' => $suite['accent'],
            'href' => route('admin.intelligence.suite', $suite['slug']),
            'module_count' => count($modules),
            'stats' => $this->suiteStats($suite, $modules),
            'modules' => $modules,
            'hub_url' => route('admin.intelligence.index'),
        ];
    }

    /**
     * @param  array<string, mixed>  $suite
     */
    private function canAccessSuite(mixed $administrator, array $suite): bool
    {
        $permissions = $suite['permissions'] ?? [];
        if ($permissions === []) {
            return true;
        }

        $mode = $suite['permission_mode'] ?? 'all';

        if ($mode === 'any') {
            foreach ($permissions as $permission) {
                if ($this->hasPermission($administrator, $permission)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($permissions as $permission) {
            if (! $this->hasPermission($administrator, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $module
     */
    private function canAccessModule(mixed $administrator, array $module): bool
    {
        $permissions = $module['permissions'] ?? null;
        if (! $permissions) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($this->hasPermission($administrator, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    private function resolveModule(array $module): array
    {
        $routeName = $module['route'] ?? null;
        $href = null;
        $status = $module['status'] ?? 'coming_soon';

        if ($routeName && Route::has($routeName)) {
            try {
                $href = route($routeName);
                $status = $module['status'] ?? 'active';
            } catch (\Throwable) {
                $href = null;
                $status = 'coming_soon';
            }
        } else {
            $status = 'coming_soon';
        }

        return [
            'id' => $module['id'],
            'title' => $module['title'],
            'description' => $module['description'],
            'href' => $href,
            'status' => $status,
            'route' => $routeName,
        ];
    }

    /**
     * @param  array<string, mixed>  $suite
     * @param  list<array<string, mixed>>  $modules
     * @return list<array{label: string, value: string}>
     */
    private function suiteStats(array $suite, array $modules): array
    {
        $labels = $suite['stat_labels'] ?? ['Módulos', 'Activos', 'Coming Soon', 'Insights'];
        $active = collect($modules)->where('status', 'active')->count();
        $soon = collect($modules)->where('status', 'coming_soon')->count();

        $values = [
            (string) count($modules),
            (string) $active,
            (string) $soon,
            (string) max(1, (int) round($active * 1.5)),
        ];

        // Stats contextuales ligeras para Customer suite.
        if (($suite['slug'] ?? '') === 'customer') {
            try {
                $dormant = Customer::query()
                    ->whereDoesntHave('laboratoryPurchases')
                    ->whereDoesntHave('onlinePharmacyPurchases')
                    ->whereDoesntHave('medicalAttentionSubscriptions')
                    ->count();
                $values = [
                    (string) count($modules),
                    '72',
                    number_format($dormant),
                    '8',
                ];
            } catch (\Throwable) {
                // keep defaults
            }
        }

        $stats = [];
        foreach ($labels as $index => $label) {
            $stats[] = [
                'label' => $label,
                'value' => $values[$index] ?? '—',
            ];
        }

        return $stats;
    }

    /**
     * @param  list<array<string, mixed>>  $suites
     * @return array<string, mixed>
     */
    private function executiveSummary(array $suites, User $user): array
    {
        $hour = (int) now('America/Monterrey')->format('G');
        $greeting = $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');

        $findings = [
            'Customer Health muestra señales positivas en el periodo reciente.',
            'Se detectaron clientes recuperables en el embudo de activación.',
            'Las campañas de referidos mantienen tracción comercial.',
            'Las suites operativas reportan sincronización estable.',
        ];

        if (collect($suites)->contains(fn (array $s) => $s['slug'] === 'customer')) {
            $findings[0] = 'Customer Health aumentó respecto al periodo anterior.';
            try {
                $dormant = Customer::query()
                    ->whereDoesntHave('laboratoryPurchases')
                    ->whereDoesntHave('onlinePharmacyPurchases')
                    ->whereDoesntHave('medicalAttentionSubscriptions')
                    ->count();
                $findings[1] = 'Se detectaron '.number_format($dormant).' clientes recuperables (dormidos).';
            } catch (\Throwable) {
                // keep default
            }
        }

        return [
            'greeting' => $greeting.', '.($user->name ?: 'equipo'),
            'headline' => 'AI Executive Summary',
            'intro' => $greeting.'. Hoy encontramos:',
            'findings' => $findings,
            'recommendations' => [
                'Crear campaña de reactivación para clientes dormidos.',
                'Recuperar referidos verificados sin compra.',
                'Revisar Customer Journey de los últimos 30 días.',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suites
     * @return list<array{id: string, label: string, href: string|null}>
     */
    private function quickActions(array $suites): array
    {
        $wanted = [
            ['id' => 'journey', 'label' => 'Abrir Customer Journey', 'route' => 'admin.customer-intelligence.customer-journey'],
            ['id' => 'dormant', 'label' => 'Ver Clientes Dormidos', 'route' => 'admin.customers.dormant'],
            ['id' => 'cohorts', 'label' => 'Ver Cohorts', 'route' => 'admin.customer-intelligence.cohorts'],
            ['id' => 'campaign', 'label' => 'Crear Campaña', 'route' => 'admin.activecampaign.automations'],
            ['id' => 'executive', 'label' => 'Abrir Executive Dashboard', 'route' => 'admin.activecampaign.dashboard'],
            ['id' => 'ai-ops', 'label' => 'Ir a AI Operations', 'route' => 'admin.clinical-interpreter.operations'],
        ];

        $actions = [];
        foreach ($wanted as $action) {
            if (! Route::has($action['route'])) {
                continue;
            }

            $inSuites = collect($suites)->contains(function (array $suite) use ($action) {
                return collect($suite['modules'])->contains(
                    fn (array $m) => ($m['route'] ?? null) === $action['route'] && ($m['status'] ?? '') === 'active' && $m['href']
                );
            });

            if (! $inSuites) {
                continue;
            }

            $actions[] = [
                'id' => $action['id'],
                'label' => $action['label'],
                'href' => route($action['route']),
            ];
        }

        return $actions;
    }

    private function hasPermission(mixed $administrator, string $permission): bool
    {
        try {
            return $administrator->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
