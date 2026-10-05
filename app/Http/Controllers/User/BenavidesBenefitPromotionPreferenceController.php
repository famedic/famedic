<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\BenavidesBenefitPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BenavidesBenefitPromotionPreferenceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $action = (string) $request->route('action');
        abort_unless(in_array($action, ['dismiss', 'click'], true), 404);

        $column = $action === 'click'
            ? 'promotion_modal_clicked_at'
            : 'promotion_modal_dismissed_at';

        $preference = BenavidesBenefitPreference::query()->firstOrCreate([
            'user_id' => $request->user()->id,
        ]);

        if ($preference->{$column} === null) {
            $preference->forceFill([$column => now()])->save();
        }

        return response()->json(['status' => 'ok']);
    }
}
