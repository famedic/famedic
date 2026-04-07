<?php

namespace App\Http\Controllers;

use App\Services\Murguia\MurguiaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MurguiaIframeController extends Controller
{
    public function __invoke(Request $request, MurguiaService $murguiaService): JsonResponse
    {
        try {
            $url = $murguiaService->getIframeUrlForUser($request->user());

            return response()->json([
                'url' => $url,
            ]);
        } catch (\Throwable $exception) {
            Log::error('No fue posible generar URL de iframe Murguia.', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'No pudimos abrir el portal de asistencias en este momento. Intenta de nuevo en unos minutos.',
            ], 503);
        }
    }
}
