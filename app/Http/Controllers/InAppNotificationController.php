<?php

namespace App\Http\Controllers;

use App\Models\InAppNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InAppNotificationController extends Controller
{
    public function markRead(Request $request, InAppNotification $inAppNotification): RedirectResponse
    {
        abort_unless($inAppNotification->user_id === $request->user()->id, 404);

        $inAppNotification->is_read = true;
        $inAppNotification->save();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        InAppNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return back();
    }
}
