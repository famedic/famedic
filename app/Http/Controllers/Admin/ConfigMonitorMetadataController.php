<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigMonitor\StoreSettingGroupRequest;
use App\Http\Requests\Admin\ConfigMonitor\StoreSettingRequest;
use App\Http\Requests\Admin\ConfigMonitor\UpdateSettingGroupRequest;
use App\Http\Requests\Admin\ConfigMonitor\UpdateSettingRequest;
use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigMonitorMetadataController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->administrator->hasPermissionTo('config_monitor.manage_metadata') || abort(403);

        $groups = SettingGroup::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->with('settings')
            ->get();

        return Inertia::render('Admin/ConfigMonitor/Metadata', [
            'groups' => $groups,
        ]);
    }

    public function storeGroup(StoreSettingGroupRequest $request): RedirectResponse
    {
        SettingGroup::query()->create($request->validated());

        return redirect()->route('admin.config-monitor.metadata.index');
    }

    public function updateGroup(UpdateSettingGroupRequest $request, SettingGroup $group): RedirectResponse
    {
        $group->update($request->validated());

        return redirect()->route('admin.config-monitor.metadata.index');
    }

    public function destroyGroup(Request $request, SettingGroup $group): RedirectResponse
    {
        $request->user()->administrator->hasPermissionTo('config_monitor.manage_metadata') || abort(403);

        $group->delete();

        return redirect()->route('admin.config-monitor.metadata.index');
    }

    public function storeSetting(StoreSettingRequest $request): RedirectResponse
    {
        Setting::query()->create($request->validated());

        return redirect()->route('admin.config-monitor.metadata.index');
    }

    public function updateSetting(UpdateSettingRequest $request, Setting $setting): RedirectResponse
    {
        $setting->update($request->validated());

        return redirect()->route('admin.config-monitor.metadata.index');
    }

    public function destroySetting(Request $request, Setting $setting): RedirectResponse
    {
        $request->user()->administrator->hasPermissionTo('config_monitor.manage_metadata') || abort(403);

        $setting->delete();

        return redirect()->route('admin.config-monitor.metadata.index');
    }
}
