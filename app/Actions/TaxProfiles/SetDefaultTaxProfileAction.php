<?php

namespace App\Actions\TaxProfiles;

use App\Models\Customer;
use App\Models\TaxProfile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SetDefaultTaxProfileAction
{
    public function __construct(
        private readonly EnsureActiveDefaultTaxProfileAction $ensureActiveDefault,
    ) {}

    public function __invoke(TaxProfile $taxProfile): TaxProfile
    {
        return DB::transaction(function () use ($taxProfile) {
            $customer = Customer::query()
                ->whereKey($taxProfile->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $profile = TaxProfile::query()
                ->whereKey($taxProfile->id)
                ->where('customer_id', $customer->id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                throw new InvalidArgumentException('El perfil fiscal no está activo o no pertenece al paciente.');
            }

            TaxProfile::query()
                ->where('customer_id', $customer->id)
                ->whereNull('deleted_at')
                ->where('id', '!=', $profile->id)
                ->where('is_default', true)
                ->orderBy('id')
                ->each(function (TaxProfile $other): void {
                    $other->forceFill(['is_default' => false])->save();
                });

            if (! $profile->is_default) {
                $profile->forceFill(['is_default' => true])->save();
            }

            ($this->ensureActiveDefault)($customer, $profile->fresh());

            return $profile->fresh();
        });
    }
}
