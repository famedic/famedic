<?php

namespace App\Http\Requests\Admin\Customers;

use App\Models\Customer;
use App\Models\OdessaAfiliateAccount;
use Illuminate\Foundation\Http\FormRequest;

class OdessaUserDataSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('customer'));
    }

    public function rules(): array
    {
        return [];
    }

    public function resolveOdessaAfiliateAccount(): OdessaAfiliateAccount
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        if ($customer->customerable_type !== OdessaAfiliateAccount::class || $customer->customerable === null) {
            abort(404, 'Este cliente no tiene cuenta Odessa.');
        }

        return $customer->customerable;
    }
}
