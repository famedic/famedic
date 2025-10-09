<?php

namespace App\Policies;

use App\Models\InvoiceRequest;
use App\Models\User;

class InvoiceRequestPolicy
{
    public function view(User $user, InvoiceRequest $invoiceRequest): bool
    {
        return $user->can('view', $invoiceRequest->invoiceRequestable);
    }
}
