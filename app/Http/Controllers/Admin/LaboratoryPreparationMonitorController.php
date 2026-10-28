<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryPurchase;
use App\Services\LaboratoryPreparation\Admin\LaboratoryPreparationMonitorAccess;
use App\Services\LaboratoryPreparation\Admin\LaboratoryPreparationMonitorService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LaboratoryPreparationMonitorController extends Controller
{
    public function __construct(
        private readonly LaboratoryPreparationMonitorAccess $access,
        private readonly LaboratoryPreparationMonitorService $service,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->allows($request->user()), 403);

        return Inertia::render(
            'Admin/LaboratoryPreparation/Monitor',
            $this->service->indexPayload($request),
        );
    }

    public function show(Request $request, LaboratoryPurchase $laboratoryPurchase): Response
    {
        abort_unless($this->access->allows($request->user()), 403);

        return Inertia::render(
            'Admin/LaboratoryPreparation/MonitorDetail',
            $this->service->detailPayload($laboratoryPurchase, $request),
        );
    }
}
