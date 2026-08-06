<?php

namespace App\Policies;

use App\Models\MarketingCampaignLink;
use App\Models\User;

class MarketingCampaignLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('marketing-campaigns.manage') ?? false;
    }

    public function view(User $user, MarketingCampaignLink $link): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('marketing-campaigns.manage.edit') ?? false;
    }

    public function update(User $user, MarketingCampaignLink $link): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, MarketingCampaignLink $link): bool
    {
        return false;
    }

    public function forceDelete(User $user, MarketingCampaignLink $link): bool
    {
        return false;
    }
}
