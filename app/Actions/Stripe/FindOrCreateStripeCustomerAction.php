<?php

namespace App\Actions\Stripe;

use App\Models\Customer;
use Stripe\Customer as StripeCustomer;
use Stripe\Stripe;

class FindOrCreateStripeCustomerAction
{
    public function __invoke(Customer $customer)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        if ($customer->stripe_id) {
            $stripeCustomer = StripeCustomer::retrieve($customer->stripe_id);
            if ((empty($stripeCustomer->email) && !empty($customer->user->email)) || (empty($stripeCustomer->name) && !empty($customer->user->full_name))) {
                StripeCustomer::update($stripeCustomer->id, ['email' => $customer->user->email, 'name' => $customer->user->full_name]);
                $stripeCustomer = StripeCustomer::retrieve($customer->stripe_id);
            }
        } else {
            $stripeCustomer = StripeCustomer::create(['email' => $customer->user->email, 'name' => $customer->user->full_name]);
            $customer->stripe_id = $stripeCustomer->id;
            $customer->save();
        }
        return $stripeCustomer;
    }
}
