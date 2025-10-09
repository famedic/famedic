<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ContactInfoUpdateRequest;
use Illuminate\Support\Facades\Redirect;
use Propaganistas\LaravelPhone\PhoneNumber;

class ContactInfoUpdateController extends Controller
{
    public function __invoke(ContactInfoUpdateRequest $request)
    {
        $request->user()->fill([
            'email' => $request->email,
            'phone' => str_replace(' ', '', (new PhoneNumber($request->phone, $request->phone_country))->formatNational()),
            'phone_country' => $request->phone_country,
        ]);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        if ($request->user()->isDirty('phone')) {
            $request->user()->phone_verified_at = null;
        }

        if ($request->user()->isDirty('email') || $request->user()->isDirty('phone')) {
            $request->user()->save();
        }

        return Redirect::route('user.edit')->flashMessage('Tu información de contacto ha sido actualizada.');
    }
}
