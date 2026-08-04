<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Murguia\MurguiaReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MurguiaReconciliationController extends Controller
{
    private const SESSION_KEY = 'murguia_reconciliation_preview';

    public function index(Request $request): Response
    {
        $preview = $request->session()->get(self::SESSION_KEY);
        $issueFilter = $request->input('issue_type', '');
        $perPage = 50;

        $allIssues = $preview['issues'] ?? [];
        if ($issueFilter !== '' && is_array($allIssues)) {
            $allIssues = array_values(array_filter(
                $allIssues,
                fn ($issue) => ($issue['issue_type'] ?? '') === $issueFilter
            ));
        }

        $page = max(1, (int) $request->input('page', 1));
        $total = count($allIssues);
        $paginatedIssues = [
            'data' => array_slice($allIssues, ($page - 1) * $perPage, $perPage),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'total' => $total,
            'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
            'to' => $total === 0 ? null : min($page * $perPage, $total),
            'prev_page_url' => $page > 1
                ? route('admin.murguia-reconciliation.index', array_filter([
                    'issue_type' => $issueFilter,
                    'page' => $page - 1,
                ]))
                : null,
            'next_page_url' => $page < max(1, (int) ceil($total / $perPage))
                ? route('admin.murguia-reconciliation.index', array_filter([
                    'issue_type' => $issueFilter,
                    'page' => $page + 1,
                ]))
                : null,
            'links' => $this->buildPaginationLinks($page, max(1, (int) ceil($total / $perPage)), $issueFilter),
        ];

        return Inertia::render('Admin/Murguia/Reconciliation', [
            'preview' => $preview ? [
                'meta' => $preview['meta'] ?? [],
                'summary' => $preview['summary'] ?? [],
                'error' => $preview['error'] ?? null,
            ] : null,
            'issues' => $paginatedIssues,
            'issueFilter' => $issueFilter,
            'issueTypes' => $this->issueTypeOptions(),
            'successMessage' => $request->session()->get('success'),
        ]);
    }

    public function upload(Request $request, MurguiaReconciliationService $reconciliationService): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv'],
        ]);

        $result = $reconciliationService->reconcile($request->file('file'));

        $request->session()->put(self::SESSION_KEY, $result);

        return redirect()
            ->route('admin.murguia-reconciliation.index')
            ->with('success', 'Archivo analizado. Revise el preview de conciliación.');
    }

    public function clear(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()
            ->route('admin.murguia-reconciliation.index')
            ->with('success', 'Preview de conciliación eliminado.');
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function issueTypeOptions(): array
    {
        return [
            ['key' => '', 'label' => 'Todas las diferencias'],
            ['key' => MurguiaReconciliationService::ISSUE_MATCHED_OK, 'label' => 'Coincidencias OK'],
            ['key' => MurguiaReconciliationService::ISSUE_PROVIDER_ONLY, 'label' => 'Solo en proveedor'],
            ['key' => MurguiaReconciliationService::ISSUE_LOCAL_ONLY, 'label' => 'Solo en BD local'],
            ['key' => MurguiaReconciliationService::ISSUE_PROVIDER_ACTIVE_LOCAL_EXPIRED, 'label' => 'Activo proveedor / vencido local'],
            ['key' => MurguiaReconciliationService::ISSUE_LOCAL_ACTIVE_PROVIDER_INACTIVE, 'label' => 'Activo local / inactivo proveedor'],
            ['key' => MurguiaReconciliationService::ISSUE_DUPLICATE_CREDITO_IN_FILE, 'label' => 'noCredito duplicado (archivo)'],
            ['key' => MurguiaReconciliationService::ISSUE_DUPLICATE_EMAIL_IN_FILE, 'label' => 'Email duplicado (archivo)'],
            ['key' => MurguiaReconciliationService::ISSUE_NAME_MISMATCH, 'label' => 'Diferencia de nombre'],
            ['key' => MurguiaReconciliationService::ISSUE_MEMBERSHIP_TYPE_MISMATCH, 'label' => 'Diferencia tipo membresía'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPaginationLinks(int $currentPage, int $lastPage, string $issueFilter): array
    {
        $links = [];

        for ($i = 1; $i <= $lastPage; $i++) {
            $links[] = [
                'url' => route('admin.murguia-reconciliation.index', array_filter([
                    'issue_type' => $issueFilter,
                    'page' => $i,
                ])),
                'label' => (string) $i,
                'active' => $i === $currentPage,
            ];
        }

        return $links;
    }
}
