<?php

namespace App\Actions\Marketing;

use App\Models\MarketingCampaignAttribution;
use App\Models\MarketingCampaignVisitorIdentity;
use App\Models\MarketingCampaignVisit;
use App\Models\User;
use App\Services\Marketing\MarketingCampaignAttributionCookieFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttachMarketingCampaignAttributionToCustomerAction
{
    public const STATUS_ATTACHED = 'attached';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_NO_CUSTOMER = 'no_customer';
    public const STATUS_NO_COOKIE = 'no_cookie';
    public const STATUS_NO_IDENTITY = 'no_identity';
    public const STATUS_NO_ACTIVE_ATTRIBUTION = 'no_active_attribution';

    public function __construct(
        private MarketingCampaignAttributionCookieFactory $cookieFactory,
    ) {}

    public function __invoke(Request $request, User $user, string $stage): string
    {
        $user->loadMissing('customer');

        if ($user->customer === null) {
            return self::STATUS_NO_CUSTOMER;
        }

        $tokenHash = $this->cookieFactory->tokenHashFromRequest($request);

        if ($tokenHash === null) {
            return self::STATUS_NO_COOKIE;
        }

        return DB::transaction(function () use ($tokenHash, $user, $stage): string {
            $identity = MarketingCampaignVisitorIdentity::query()
                ->where('visitor_token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($identity === null) {
                return self::STATUS_NO_IDENTITY;
            }

            $attribution = MarketingCampaignAttribution::query()
                ->where('marketing_campaign_visitor_identity_id', $identity->id)
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($attribution === null) {
                return self::STATUS_NO_ACTIVE_ATTRIBUTION;
            }

            $customerId = (int) $user->customer->id;
            $userId = (int) $user->id;

            if (
                $this->belongsToAnotherCustomer($attribution, $userId, $customerId)
                || $this->hasVisitsAttachedToAnotherCustomer($attribution, $userId, $customerId)
            ) {
                Log::warning('marketing_campaign_attribution_attach_conflict', [
                    'stage' => $stage,
                    'marketing_campaign_attribution_id' => $attribution->id,
                    'marketing_campaign_visitor_identity_id' => $identity->id,
                    'reason' => 'already_attached_to_another_customer',
                ]);

                return self::STATUS_CONFLICT;
            }

            $updates = array_filter([
                'user_id' => $attribution->user_id === null ? $userId : null,
                'customer_id' => $attribution->customer_id === null ? $customerId : null,
            ], static fn (?int $value): bool => $value !== null);

            if ($updates !== []) {
                $attribution->forceFill($updates)->save();
            }

            MarketingCampaignVisit::identifyForAttribution($attribution, $userId, $customerId);

            return self::STATUS_ATTACHED;
        });
    }

    private function belongsToAnotherCustomer(
        MarketingCampaignAttribution $attribution,
        int $userId,
        int $customerId,
    ): bool {
        return ($attribution->user_id !== null && (int) $attribution->user_id !== $userId)
            || ($attribution->customer_id !== null && (int) $attribution->customer_id !== $customerId);
    }

    private function hasVisitsAttachedToAnotherCustomer(
        MarketingCampaignAttribution $attribution,
        int $userId,
        int $customerId,
    ): bool {
        return MarketingCampaignVisit::query()
            ->where('marketing_campaign_attribution_id', $attribution->id)
            ->where(function ($query) use ($userId, $customerId) {
                $query
                    ->where(function ($query) use ($userId) {
                        $query->whereNotNull('user_id')
                            ->where('user_id', '!=', $userId);
                    })
                    ->orWhere(function ($query) use ($customerId) {
                        $query->whereNotNull('customer_id')
                            ->where('customer_id', '!=', $customerId);
                    });
            })
            ->exists();
    }
}
