<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Customers\DeleteNonProductionCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customers\DeleteTestCustomerRequest;
use App\Models\Customer;

class CustomerTestDeletionController extends Controller
{
    public function destroy(
        DeleteTestCustomerRequest $request,
        Customer $customer,
        DeleteNonProductionCustomerAction $action,
    ) {
        abort_if(app()->isProduction(), 403);

        $action->execute($customer, $request->user());

        return redirect()
            ->route('admin.customers.index')
            ->with('success', 'Usuario de prueba eliminado correctamente.');
    }
}
