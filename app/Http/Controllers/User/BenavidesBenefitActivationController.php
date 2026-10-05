<?php

namespace App\Http\Controllers\User;

use App\Exceptions\OutOfBenavidesCodesException;
use App\Http\Controllers\Controller;
use App\Models\BenavidesCode;
use App\Services\Benavides\BenavidesBenefitAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BenavidesBenefitActivationController extends Controller
{
    public function __invoke(Request $request, BenavidesBenefitAssignmentService $assignmentService): JsonResponse|RedirectResponse
    {
        if (! (bool) config('famedic.benavides_benefit.enabled', false)) {
            return $this->errorResponse(
                $request,
                'BENAVIDES_BENEFIT_DISABLED',
                'El beneficio Farmacias Benavides no está disponible por el momento.',
                403
            );
        }

        try {
            $code = $assignmentService->assignTo($request->user());
        } catch (OutOfBenavidesCodesException) {
            return $this->errorResponse(
                $request,
                'BENAVIDES_CODES_EXHAUSTED',
                'Por el momento todos los beneficios disponibles han sido asignados. Intenta nuevamente más adelante.',
                409
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'assigned',
                'message' => 'Beneficio Farmacias Benavides activado correctamente.',
                'benavides_code' => $this->codePayload($code),
            ]);
        }

        return back()->flashMessage('Beneficio Farmacias Benavides activado correctamente.');
    }

    private function errorResponse(Request $request, string $code, string $message, int $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'code' => $code,
                'message' => $message,
            ], $status);
        }

        return back()->flashMessage($message, 'error');
    }

    private function codePayload(BenavidesCode $code): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'assigned_at' => $code->assigned_at?->toIso8601String(),
        ];
    }
}
