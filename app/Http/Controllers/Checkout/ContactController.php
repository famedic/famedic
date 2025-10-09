<?php

namespace App\Http\Controllers\Checkout;

use App\Actions\Contacts\CreateContactAction;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreContactRequest;
use Carbon\Carbon;

class ContactController extends Controller
{
    public function __invoke(StoreContactRequest $request, CreateContactAction $action)
    {
        $contact = $action(
            name: $request->name,
            paternal_lastname: $request->paternal_lastname,
            maternal_lastname: $request->maternal_lastname,
            birth_date: Carbon::parse($request->birth_date),
            gender: Gender::from($request->gender),
            phone: $request->phone,
            phone_country: $request->phone_country,
            customer: $request->user()->customer
        );

        return response()->json([
            'contact' => $contact->id
        ]);
    }
}
