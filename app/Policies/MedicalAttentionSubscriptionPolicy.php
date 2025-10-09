<?php

namespace App\Policies;

use App\Models\MedicalAttentionSubscription;
use App\Models\User;

class MedicalAttentionSubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('medical-attention-subscriptions.manage');
    }

    public function view(User $user, MedicalAttentionSubscription $medicalAttentionSubscription): bool
    {
        return $user->customer?->id === $medicalAttentionSubscription->customer_id || $user->administrator?->hasPermissionTo('medical-attention-subscriptions.manage');
    }

    public function update(User $user, MedicalAttentionSubscription $medicalAttentionSubscription): bool
    {
        return $user->customer?->id === $medicalAttentionSubscription->customer_id || $user->administrator?->hasPermissionTo('medical-attention-subscriptions.manage');
    }

    public function delete(User $user, MedicalAttentionSubscription $medicalAttentionSubscription): bool
    {
        return $user->administrator?->hasPermissionTo('medical-attention-subscriptions.manage');
    }
}
