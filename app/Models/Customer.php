<?php

namespace App\Models;

use App\Actions\Stripe\FindOrCreateStripeCustomerAction;
use App\Enums\LaboratoryBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\App;
use Laravel\Cashier\Billable;

class Customer extends Model
{
    use Billable, HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'formatted_medical_attention_subscription_expires_at',
        'formatted_created_at',
        'medical_attention_subscription_is_active',
    ];

    protected function casts(): array
    {
        return [
            'medical_attention_subscription_expires_at' => 'datetime',
        ];
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->whereHas('user', function ($query) use ($search) {
                        $query->where(function ($query) use ($search) {
                            $query->where('name', 'like', '%' . $search . '%')
                                ->orWhere('paternal_lastname', 'like', '%' . $search . '%')
                                ->orWhere('maternal_lastname', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%');
                        });
                    })
                        ->orWhereHasMorph('customerable', [OdessaAfiliateAccount::class], function ($query) use ($search) {
                            $query->where('odessa_identifier', 'like', '%' . $search . '%')
                                ->orWhere('partner_identifier', 'like', '%' . $search . '%')
                                ->orWhere('odessa_afiliated_company_id', 'like', '%' . $search . '%')
                                ->orWhereHas('odessaAfiliatedCompany', function ($query) use ($search) {
                                    $query->where('name', 'like', '%' . $search . '%');
                                });
                        })
                        ->orWhereHasMorph('customerable', [FamilyAccount::class], function ($query) use ($search) {
                            $query->where('name', 'like', '%' . $search . '%')
                                ->orWhere('paternal_lastname', 'like', '%' . $search . '%')
                                ->orWhere('maternal_lastname', 'like', '%' . $search . '%');
                        });
                });
            })
            ->when(isset($filters['type']) && $filters['type'] !== '', function ($query) use ($filters) {
                $modelMap = [
                    'regular' => RegularAccount::class,
                    'odessa' => OdessaAfiliateAccount::class,
                    'familiar' => FamilyAccount::class,
                ];

                if (isset($modelMap[$filters['type']])) {
                    $query->where('customerable_type', $modelMap[$filters['type']]);
                }
            })
            ->when(isset($filters['medical_attention_status']) && $filters['medical_attention_status'] !== '', function ($query) use ($filters) {
                if ($filters['medical_attention_status'] === 'active') {
                    $query->where('medical_attention_subscription_expires_at', '>', now());
                } elseif ($filters['medical_attention_status'] === 'inactive') {
                    $query->where(function ($query) {
                        $query->whereNull('medical_attention_subscription_expires_at')
                            ->orWhere('medical_attention_subscription_expires_at', '<=', now());
                    });
                }
            })
            ->when($filters['start_date'] ?? null, function ($query, $startDate) {
                $query->where('created_at', '>=', \Carbon\Carbon::parse($startDate, 'America/Monterrey')->setTimezone('UTC'));
            })
            ->when($filters['end_date'] ?? null, function ($query, $endDate) {
                $query->where('created_at', '<=', \Carbon\Carbon::parse($endDate, 'America/Monterrey')->endOfDay()->setTimezone('UTC'));
            })
            ->when(isset($filters['referral_status']) && $filters['referral_status'] !== '', function ($query) use ($filters) {
                if ($filters['referral_status'] === 'referred') {
                    $query->whereHas('user', function ($query) {
                        $query->whereNotNull('referred_by');
                    });
                } elseif ($filters['referral_status'] === 'not_referred') {
                    $query->whereHas('user', function ($query) {
                        $query->whereNull('referred_by');
                    });
                }
            })
            ->when(isset($filters['verification_status']) && $filters['verification_status'] !== '', function ($query) use ($filters) {
                if ($filters['verification_status'] === 'verified') {
                    $query->whereHas('user', function ($query) {
                        $query->whereNotNull('email_verified_at')
                            ->whereNotNull('phone_verified_at');
                    });
                } elseif ($filters['verification_status'] === 'unverified') {
                    $query->whereHas('user', function ($query) {
                        $query->where(function ($query) {
                            $query->whereNull('email_verified_at')
                                ->orWhereNull('phone_verified_at');
                        });
                    });
                }
            });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function taxProfiles(): HasMany
    {
        return $this->hasMany(TaxProfile::class);
    }

    public function customerable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function laboratoryPurchases(): HasMany
    {
        return $this->hasMany(LaboratoryPurchase::class);
    }

    public function onlinePharmacyPurchases(): HasMany
    {
        return $this->hasMany(OnlinePharmacyPurchase::class);
    }

    public function laboratoryCartItems(): HasMany
    {
        return $this->hasMany(LaboratoryCartItem::class);
    }

    public function laboratoryAppointments(): HasMany
    {
        return $this->hasMany(LaboratoryAppointment::class);
    }

    public function onlinePharmacyCartItems(): HasMany
    {
        return $this->hasMany(OnlinePharmacyCartItem::class);
    }

    public function medicalAttentionSubscriptions(): HasMany
    {
        return $this->hasMany(MedicalAttentionSubscription::class);
    }

    public function familyAccounts(): HasMany
    {
        return $this->hasMany(FamilyAccount::class);
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyAccount::class, 'customer_id');
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn() => localizedDate($this->created_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedMedicalAttentionSubscriptionExpiresAt(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->medical_attention_subscription_expires_at?->isoFormat('D [de] MMM [de] YYYY'),
        );
    }

    protected function formattedAccountType(): Attribute
    {
        return Attribute::make(
            get: fn() => match ($this->customerable_type) {
                OdessaAfiliateAccount::class => 'Afiliado Odessa',
                FamilyAccount::class => 'Familiar',
                default => 'Regular',
            },
        );
    }

    protected function hasOdessaAfiliateAccount(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->customerable_type === OdessaAfiliateAccount::class,
        );
    }

    protected function hasRegularAccount(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->customerable_type === RegularAccount::class,
        );
    }

    protected function stripeCustomer(): Attribute
    {
        return Attribute::make(
            get: function () {
                $action = App::make(FindOrCreateStripeCustomerAction::class);

                return $action($this);
            },
        );
    }

    protected function medicalAttentionSubscriptionIsActive(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->medical_attention_subscription_expires_at && now()->lte($this->medical_attention_subscription_expires_at);
            },
        );
    }

    public function getHasLaboratoryCartItemRequiringAppointment(LaboratoryBrand $laboratoryBrand): bool
    {
        return $this->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->requiringAppointment()
            ->exists();
    }

    public function getRecentlyConfirmedUncompletedLaboratoryAppointment(LaboratoryBrand $laboratoryBrand): ?LaboratoryAppointment
    {
        return $this->laboratoryAppointments()
            ->recentlyConfirmed()
            ->uncompleted()
            ->ofBrand($laboratoryBrand)
            ->with('laboratoryStore')
            ->first();
    }

    public function getPendingLaboratoryAppointment(LaboratoryBrand $laboratoryBrand): ?LaboratoryAppointment
    {
        return $this->laboratoryAppointments()
            ->unconfirmed()
            ->ofBrand($laboratoryBrand)
            ->first();
    }

    // En app/Models/Customer.php
    public function laboratoryQuotes()
    {
        return $this->hasMany(LaboratoryQuote::class);
    }
}
