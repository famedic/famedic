<?php

namespace App\Models;

use App\Enums\CouponBeneficiarySource;
use App\Enums\CouponBeneficiaryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponBeneficiary extends Model
{
    public const INVITATION_RESEND_COOLDOWN_MINUTES = 10;

    protected $fillable = [
        'parent_coupon_id',
        'child_coupon_id',
        'user_id',
        'email',
        'email_normalized',
        'first_name',
        'paternal_lastname',
        'maternal_lastname',
        'status',
        'source',
        'import_batch_id',
        'assigned_at',
        'claimed_at',
        'cancelled_at',
        'invitation_sent_at',
        'last_invitation_sent_at',
        'invitation_count',
        'activated_at',
        'activation_notified_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CouponBeneficiaryStatus::class,
            'source' => CouponBeneficiarySource::class,
            'assigned_at' => 'datetime',
            'claimed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'invitation_sent_at' => 'datetime',
            'last_invitation_sent_at' => 'datetime',
            'invitation_count' => 'integer',
            'activated_at' => 'datetime',
            'activation_notified_at' => 'datetime',
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function parentCoupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'parent_coupon_id');
    }

    public function childCoupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'child_coupon_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function isPendingUser(): bool
    {
        return $this->status === CouponBeneficiaryStatus::PendingUser;
    }

    public function isAssigned(): bool
    {
        return $this->status === CouponBeneficiaryStatus::Assigned;
    }

    public function isCancelled(): bool
    {
        return $this->status === CouponBeneficiaryStatus::Cancelled;
    }

    public function hasInvitationBeenSent(): bool
    {
        return $this->invitation_sent_at !== null;
    }

    public function canResendInvitation(): bool
    {
        if (! $this->isPendingUser() || $this->user_id !== null || $this->child_coupon_id !== null) {
            return false;
        }

        if ($this->last_invitation_sent_at === null) {
            return true;
        }

        return $this->last_invitation_sent_at->lte(
            now()->subMinutes(self::INVITATION_RESEND_COOLDOWN_MINUTES)
        );
    }

    public function resendInvitationAvailableAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->last_invitation_sent_at === null || $this->canResendInvitation()) {
            return null;
        }

        return $this->last_invitation_sent_at->copy()->addMinutes(self::INVITATION_RESEND_COOLDOWN_MINUTES);
    }

    public function hasActivationNotificationBeenSent(): bool
    {
        return $this->activation_notified_at !== null;
    }

    public function markInvitationSent(): void
    {
        $now = now();

        if ($this->invitation_sent_at === null) {
            $this->invitation_sent_at = $now;
        }

        $this->last_invitation_sent_at = $now;
        $this->invitation_count = (int) $this->invitation_count + 1;
        $this->save();
    }

    public function markActivationNotified(): void
    {
        $this->activation_notified_at = now();
        $this->save();
    }

    public function scopeActiveForParent(Builder $query, int $parentCouponId): Builder
    {
        return $query
            ->where('parent_coupon_id', $parentCouponId)
            ->whereNull('cancelled_at')
            ->whereIn('status', [
                CouponBeneficiaryStatus::PendingUser->value,
                CouponBeneficiaryStatus::Assigned->value,
            ]);
    }
}
