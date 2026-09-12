<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaignVisit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => false);
        static::deleting(static fn () => false);
    }

    public static function identifyForAttribution(
        MarketingCampaignAttribution $attribution,
        int $userId,
        int $customerId,
    ): int {
        return static::query()
            ->where('marketing_campaign_attribution_id', $attribution->id)
            ->whereNull('user_id')
            ->whereNull('customer_id')
            ->update([
                'user_id' => $userId,
                'customer_id' => $customerId,
            ]);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignLink::class, 'marketing_campaign_link_id');
    }

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignAttribution::class, 'marketing_campaign_attribution_id');
    }

    public function visitorIdentity(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaignVisitorIdentity::class, 'marketing_campaign_visitor_identity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
