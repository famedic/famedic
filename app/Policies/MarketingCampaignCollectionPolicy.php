<?php

namespace App\Policies;

use App\Models\MarketingCampaignCollection;
use App\Models\User;

class MarketingCampaignCollectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('marketing-campaigns.manage') ?? false;
    }

    public function view(User $user, MarketingCampaignCollection $collection): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('marketing-campaigns.manage.edit') ?? false;
    }

    public function update(User $user, MarketingCampaignCollection $collection): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, MarketingCampaignCollection $collection): bool
    {
        return false;
    }

    public function forceDelete(User $user, MarketingCampaignCollection $collection): bool
    {
        return false;
    }
}
