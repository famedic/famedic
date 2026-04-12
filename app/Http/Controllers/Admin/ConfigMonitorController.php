<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ConfigMonitorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigMonitorController extends Controller
{
    public function index(Request $request, ConfigMonitorService $configMonitorService): Response
    {
        $request->user()->administrator->hasPermissionTo('view_config_monitor') || abort(403);

        return Inertia::render('Admin/ConfigMonitor/Index', [
            'report' => $configMonitorService->buildReport(),
            'canManageMetadata' => $request->user()->administrator->hasPermissionTo('config_monitor.manage_metadata'),
        ]);
    }

    public function refresh(Request $request, ConfigMonitorService $configMonitorService): RedirectResponse
    {
        $request->user()->administrator->hasPermissionTo('view_config_monitor') || abort(403);

        $configMonitorService->clearDotEnvCache();

        return redirect()
            ->route('admin.config-monitor.index')
            ->flashMessage('Datos del monitor actualizados para esta solicitud.');
    }
}
