<?php

namespace App\Policies;

use App\Models\MarketingCampaign;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

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

    public function viewAttributedUsers(User $user, MarketingCampaign $campaign): bool
    {
        return $this->view($user, $campaign);
    }

    public function viewAttributedUserPii(User $user, MarketingCampaign $campaign): bool
    {
        try {
            return $user->administrator?->hasPermissionTo('marketing-campaigns.attributed-users.view-pii') ?? false;
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public function create(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('marketing-campaigns.manage.edit') ?? false;
    }

    public function update(User $user, MarketingCampaign $campaign): bool
    {
        if ($campaign->isArchived()) {
            return false;
        }

        return $this->create($user);
    }

    /**
     * Archivar (idempotente): requiere edit aunque ya esté archived.
     */
    public function archive(User $user, MarketingCampaign $campaign): bool
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
