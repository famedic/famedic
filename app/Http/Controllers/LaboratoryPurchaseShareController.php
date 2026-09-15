<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CreateLaboratoryPurchaseShare;
use App\Actions\Laboratories\RevokeLaboratoryPurchaseShare;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class LaboratoryPurchaseShareController extends Controller
{
    public function store(
        Request $request,
        LaboratoryPurchase $laboratoryPurchase,
        CreateLaboratoryPurchaseShare $createLaboratoryPurchaseShare,
    ): JsonResponse {
        $this->authorize('createShare', $laboratoryPurchase);

        try {
            $result = $createLaboratoryPurchaseShare($laboratoryPurchase, $request->user());
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        /** @var LaboratoryPurchaseShare $share */
        $share = $result['share'];

        return response()->json([
            'share' => $this->presentShare($share),
            'url' => $result['url'],
        ], 201);
    }

    public function destroy(
        Request $request,
        LaboratoryPurchase $laboratoryPurchase,
        LaboratoryPurchaseShare $share,
        RevokeLaboratoryPurchaseShare $revokeLaboratoryPurchaseShare,
    ): JsonResponse {
        $this->authorize('revokeShare', $laboratoryPurchase);

        $share = $revokeLaboratoryPurchaseShare($laboratoryPurchase, $share, $request->user());

        return response()->json([
            'revoked' => true,
            'share' => $this->presentShare($share),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentShare(LaboratoryPurchaseShare $share): array
    {
        return [
            'id' => $share->id,
            'expires_at' => $share->expires_at?->toIso8601String(),
            'formatted_expires_at' => $share->expires_at
                ? localizedDate($share->expires_at)->isoFormat('D MMM Y h:mm a')
                : null,
            'revoked_at' => $share->revoked_at?->toIso8601String(),
        ];
    }
}
