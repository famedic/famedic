<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SimulatorController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->administrator->hasPermissionTo('simulators.manage') || abort(403);

        return Inertia::render('Admin/Simulators/Index');
    }
}
