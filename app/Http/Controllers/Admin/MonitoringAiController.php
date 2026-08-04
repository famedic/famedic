<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MonitoringAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringAiController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->administrator->hasPermissionTo('monitoring-ai.manage') || abort(403);

        return Inertia::render('Admin/MonitoringAi/Index', [
            'isConfigured' => filled(config('services.openai.key')),
        ]);
    }

    public function ask(Request $request, MonitoringAiService $monitoringAiService): JsonResponse
    {
        $request->user()->administrator->hasPermissionTo('monitoring-ai.manage') || abort(403);

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            $answer = $monitoringAiService->ask($validated['question']);

            return response()->json([
                'answer' => $answer,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo obtener una respuesta del asistente. ' . $e->getMessage(),
            ], 422);
        }
    }
}
