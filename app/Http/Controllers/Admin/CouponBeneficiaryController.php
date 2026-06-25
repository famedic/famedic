<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\CouponBeneficiaryReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CouponBeneficiaryController extends Controller
{
    public function __construct(
        private CouponBeneficiaryReportService $reportService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Coupon::class);

        $filters = $this->filtersFromRequest($request);
        $report = $this->reportService->paginate($filters);

        return Inertia::render('Admin/Coupons/Beneficiaries', [
            'beneficiaries' => $report['rows'],
            'summary' => $report['summary'],
            'filters' => $filters,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Coupon::class);

        $filters = $this->filtersFromRequest($request);
        $rows = $this->reportService->exportRows($filters);

        $filename = 'beneficiarios-creditos-'.now()->format('Y-m-d_His').'.csv';

        return Response::streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'nombre',
                'email',
                'estado',
                'creditos_asignados',
                'pendientes',
                'saldo_disponible',
                'saldo_utilizado',
                'saldo_restaurado',
                'ultima_asignacion',
                'ultimo_uso',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['full_name'] ?? '',
                    $row['email'],
                    $row['status'] === 'registered' ? 'Registrado' : 'Pendiente de registro',
                    $row['assigned_coupons_count'],
                    $row['pending_beneficiaries_count'],
                    number_format($row['available_balance_cents'] / 100, 2, '.', ''),
                    number_format($row['used_balance_cents'] / 100, 2, '.', ''),
                    number_format($row['reversed_balance_cents'] / 100, 2, '.', ''),
                    $row['last_assigned_at'] ?? '',
                    $row['last_used_at'] ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersFromRequest(Request $request): array
    {
        return [
            'search' => $request->input('search', ''),
            'status' => $request->input('status', 'all'),
            'balance' => $request->input('balance', 'all'),
            'has_pending' => $request->boolean('has_pending'),
            'assigned_from' => $request->input('assigned_from', ''),
            'assigned_to' => $request->input('assigned_to', ''),
            'used_from' => $request->input('used_from', ''),
            'used_to' => $request->input('used_to', ''),
        ];
    }
}
