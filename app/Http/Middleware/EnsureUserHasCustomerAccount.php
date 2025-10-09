<?php

namespace App\Http\Middleware;

use App\Actions\Customers\CreateRegularAccountCustomerAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasCustomerAccount
{
    public function __construct(
        private CreateRegularAccountCustomerAction $createCustomer
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->user()?->customer) {
            ($this->createCustomer)(auth()->user());
        }
        return $next($request);
    }
}
