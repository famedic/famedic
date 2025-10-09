<?php

namespace App\Actions;

use App\Models\Administrator;
use App\Models\DevAssistanceRequest;
use Illuminate\Support\Facades\DB;

class ReopenDevAssistanceRequestAction
{
    public function __invoke(
        DevAssistanceRequest $devAssistanceRequest,
        Administrator $administrator,
        string $comment
    ): DevAssistanceRequest {
        DB::beginTransaction();

        try {
            $devAssistanceRequest->update([
                'resolved_at' => null,
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
