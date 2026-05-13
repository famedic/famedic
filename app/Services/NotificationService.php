<?php

namespace App\Services;

use App\Models\InAppNotification;
use App\Models\User;

class NotificationService
{
    public function createNotification(User $user, string $type, string $title, string $message): InAppNotification
    {
        return InAppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'is_read' => false,
        ]);
    }

    public function unreadCount(User $user): int
    {
        return InAppNotification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->count();
    }

    public function recentForUser(User $user, int $limit = 15): \Illuminate\Support\Collection
    {
        return InAppNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
