<?php

namespace App\Services\Workspace;

use App\Models\Customer;
use App\Models\User;
use App\Support\Workspace\WorkspaceCatalog;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class WorkspaceService
{
    /**
     * @return array{
     *   workspaces: list<array<string, mixed>>,
     *   recommendations: array<string, mixed>,
     *   quick_actions: list<array<string, mixed>>,
     *   search_index: list<array<string, mixed>>
     * }
     */
    public function buildFor(mixed $administrator, User $user): array
    {
        $workspaces = [];
        $searchIndex = [];

        foreach (WorkspaceCatalog::workspaces() as $workspace) {
            if (! $this->canAccessWorkspace($administrator, $workspace)) {
                continue;
            }

            $tools = [];
            foreach ($workspace['tools'] as $tool) {
                if (! $this->canAccessTool($administrator, $tool)) {
                    continue;
                }

                $resolved = $this->resolveTool($tool);
                $tools[] = $resolved;

                $searchIndex[] = [
                    'type' => 'tool',
                    'workspace' => $workspace['name'],
                    'workspace_slug' => $workspace['slug'],
                    'title' => $resolved['title'],
                    'description' => $resolved['description'],
                    'href' => $resolved['href'],
                    'status' => $resolved['status'],
                    'keywords' => implode(' ', [
                        $resolved['title'],
                        $workspace['name'],
                        $resolved['description'],
                        'dashboard herramienta insight automatizacion',
                    ]),
                ];
            }

            if ($tools === []) {
                continue;
            }

            $payload = [
                'id' => $workspace['id'],
                'slug' => $workspace['slug'],
                'emoji' => $workspace['emoji'],
                'name' => $workspace['name'],
                'description' => $workspace['description'],
                'accent' => $workspace['accent'],
                'featured' => (bool) ($workspace['featured'] ?? false),
                'cta' => $workspace['cta'] ?? 'Abrir',
                'href' => route('admin.workspace.show', $workspace['slug']),
                'tools' => $tools,
            ];

            $workspaces[] = $payload;

            $searchIndex[] = [
                'type' => 'workspace',
                'workspace' => $workspace['name'],
                'workspace_slug' => $workspace['slug'],
                'title' => $workspace['name'],
                'description' => $workspace['description'],
                'href' => $payload['href'],
                'status' => 'active',
                'keywords' => $workspace['name'].' '.$workspace['description'],
            ];
        }

        // Orden: featured primero, luego el resto del catálogo.
        usort($workspaces, function (array $a, array $b) {
            return ((int) (! $a['featured'])) <=> ((int) (! $b['featured']));
        });

        return [
            'workspaces' => $workspaces,
            'recommendations' => $this->recommendations($workspaces, $user),
            'quick_actions' => $this->quickActions($workspaces),
            'search_index' => $searchIndex,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function workspaceFor(mixed $administrator, string $slug): ?array
    {
        $workspace = WorkspaceCatalog::find($slug);
        if (! $workspace || ! $this->canAccessWorkspace($administrator, $workspace)) {
            return null;
        }

        $tools = [];
        foreach ($workspace['tools'] as $tool) {
            if (! $this->canAccessTool($administrator, $tool)) {
                continue;
            }
            $tools[] = $this->resolveTool($tool);
        }

        return [
            'id' => $workspace['id'],
            'slug' => $workspace['slug'],
            'emoji' => $workspace['emoji'],
            'name' => $workspace['name'],
            'description' => $workspace['description'],
            'accent' => $workspace['accent'],
            'featured' => (bool) ($workspace['featured'] ?? false),
            'cta' => $workspace['cta'] ?? 'Abrir',
            'href' => route('admin.workspace.show', $workspace['slug']),
            'tools' => $tools,
            'home_url' => route('admin.workspace.index'),
        ];
    }

    /**
     * @param  array<string, mixed>  $workspace
     */
    private function canAccessWorkspace(mixed $administrator, array $workspace): bool
    {
        $permissions = $workspace['permissions'] ?? [];
        if ($permissions === []) {
            return true;
        }

        $mode = $workspace['permission_mode'] ?? 'all';

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
     * @param  array<string, mixed>  $tool
     */
    private function canAccessTool(mixed $administrator, array $tool): bool
    {
        $permissions = $tool['permissions'] ?? null;
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
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function resolveTool(array $tool): array
    {
        $routeName = $tool['route'] ?? null;
        $href = null;
        $status = $tool['status'] ?? 'coming_soon';

        if ($routeName && Route::has($routeName)) {
            try {
                $href = route($routeName);
                $status = $tool['status'] ?? 'active';
            } catch (\Throwable) {
                $href = null;
                $status = 'coming_soon';
            }
        } else {
            $status = 'coming_soon';
        }

        return [
            'id' => $tool['id'],
            'title' => $tool['title'],
            'description' => $tool['description'],
            'href' => $href,
            'status' => $status,
            'route' => $routeName,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $workspaces
     * @return array<string, mixed>
     */
    private function recommendations(array $workspaces, User $user): array
    {
        $items = [
            'Hoy recomendamos revisar clientes dormidos con potencial de reactivación.',
            'Existen interpretaciones clínicas pendientes de seguimiento.',
            'Hay campañas con alto rendimiento listas para escalar.',
            'El Customer Health promedio muestra señales positivas.',
        ];

        $hasCustomers = collect($workspaces)->contains(fn (array $w) => $w['slug'] === 'customers');
        if ($hasCustomers) {
            try {
                $dormant = Customer::query()
                    ->whereDoesntHave('laboratoryPurchases')
                    ->whereDoesntHave('onlinePharmacyPurchases')
                    ->whereDoesntHave('medicalAttentionSubscriptions')
                    ->count();
                $items[0] = 'Hoy recomendamos revisar '.number_format($dormant).' clientes dormidos.';
            } catch (\Throwable) {
                // keep default
            }
        }

        $hasClinical = collect($workspaces)->contains(fn (array $w) => $w['slug'] === 'clinical-ai');
        if ($hasClinical) {
            $items[1] = 'Existen recetas e interpretaciones listas para revisar en IA Clínica.';
        }

        return [
            'title' => 'Recomendaciones Inteligentes',
            'items' => $items,
            'cta_label' => 'Ver recomendaciones',
            'cta_href' => collect($workspaces)->firstWhere('slug', 'customers')['href']
                ?? collect($workspaces)->first()['href']
                ?? route('admin.workspace.index'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $workspaces
     * @return list<array{id: string, label: string, href: string}>
     */
    private function quickActions(array $workspaces): array
    {
        $wanted = [
            ['id' => 'interpret', 'label' => 'Interpretar receta', 'route' => 'admin.clinical-interpreter.index'],
            ['id' => 'search-customer', 'label' => 'Buscar cliente', 'route' => 'admin.customers.index'],
            ['id' => 'campaign', 'label' => 'Crear campaña', 'route' => 'admin.activecampaign.automations'],
            ['id' => 'executive', 'label' => 'Ver Dashboard Ejecutivo', 'route' => 'admin.activecampaign.dashboard'],
        ];

        $actions = [];
        foreach ($wanted as $action) {
            if (! Route::has($action['route'])) {
                continue;
            }

            $available = collect($workspaces)->contains(function (array $workspace) use ($action) {
                return collect($workspace['tools'])->contains(
                    fn (array $t) => ($t['route'] ?? null) === $action['route']
                        && ($t['status'] ?? '') === 'active'
                        && $t['href']
                );
            });

            // Buscar cliente puede estar en customers tools via customers.index
            if (! $available && $action['route'] === 'admin.customers.index') {
                $available = collect($workspaces)->contains(fn (array $w) => $w['slug'] === 'customers');
            }

            if (! $available) {
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
