<?php

namespace App\Actions;

use App\Models\Administrator;
use App\Models\DevAssistanceComment;
use App\Models\DevAssistanceRequest;
use Illuminate\Support\Facades\DB;

class AddDevAssistanceCommentAction
{
    public function __invoke(
        DevAssistanceRequest $devAssistanceRequest,
        Administrator $administrator,
        string $comment,
        bool $markResolved = false
    ): DevAssistanceComment {
        DB::beginTransaction();

        try {
            $devAssistanceComment = $devAssistanceRequest->comments()->create([
                'administrator_id' => $administrator->id,
                'comment' => $comment,
            ]);

            if ($markResolved && ! $devAssistanceRequest->resolved_at) {
                $devAssistanceRequest->update([
                    'resolved_at' => now(),
                ]);
            }

            DB::commit();

            return $devAssistanceComment;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
