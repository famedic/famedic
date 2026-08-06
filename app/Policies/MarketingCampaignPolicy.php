<?php

namespace App\Policies;

use App\Models\MarketingCampaign;
use App\Models\User;

class MarketingCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('marketing-campaigns.manage') ?? false;
    }

    public function view(User $user, MarketingCampaign $campaign): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('marketing-campaigns.manage.edit') ?? false;
    }

    public function update(User $user, MarketingCampaign $campaign): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, MarketingCampaign $campaign): bool
    {
        return false;
    }

    public function forceDelete(User $user, MarketingCampaign $campaign): bool
    {
        return false;
    }
}
