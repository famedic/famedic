<?php

namespace App\Actions;

use App\Models\Administrator;
use App\Models\DevAssistanceRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateDevAssistanceRequestAction
{
    public function __invoke(
        Model $purchaseModel,
        Administrator $administrator,
        string $comment
    ): DevAssistanceRequest {
        DB::beginTransaction();

        try {
            $devAssistanceRequest = $purchaseModel->devAssistanceRequests()->create([
                'administrator_id' => $administrator->id,
                'requested_at' => now(),
            ]);

            $devAssistanceRequest->comments()->create([
                'administrator_id' => $administrator->id,
                'comment' => $comment,
            ]);

            DB::commit();

            return $devAssistanceRequest;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
