<?php

namespace App\Actions\TaxProfiles;

use App\Models\Customer;
use App\Models\TaxProfile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DestroyTaxProfileAction
{
    public function __construct(
        private readonly EnsureActiveDefaultTaxProfileAction $ensureActiveDefault,
    ) {}

    /**
     * Desactivación lógica (SoftDeletes). Conserva la constancia en Storage.
     */
    public function __invoke(TaxProfile $taxProfile): void
    {
        DB::transaction(function () use ($taxProfile) {
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

            // Inactivo no es predeterminado operativo.
            if ($profile->is_default) {
                $profile->forceFill(['is_default' => false])->save();
            }

            $profile->delete();

            ($this->ensureActiveDefault)($customer);
        });
    }
}
