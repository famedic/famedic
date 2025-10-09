<?php

namespace App\Http\Controllers;

use App\Actions\Contacts\CreateContactAction;
use App\Actions\Contacts\DestroyContactAction;
use App\Actions\Contacts\UpdateContactAction;
use App\Enums\Gender;
use App\Http\Requests\Contacts\DestroyContactRequest;
use App\Http\Requests\Contacts\EditContactRequest;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Contacts', [
            'contacts' => $request->user()->customer->contacts,
            'genders' => Gender::casesWithLabels(),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Contacts', [
            'contacts' => $request->user()->customer->contacts,
            'genders' => Gender::casesWithLabels(),
        ]);
    }

    public function store(StoreContactRequest $request, CreateContactAction $action)
    {
        $action(
            name: $request->name,
            paternal_lastname: $request->paternal_lastname,
            maternal_lastname: $request->maternal_lastname,
            birth_date: Carbon::parse($request->birth_date),
            gender: Gender::from($request->gender),
            phone: $request->phone,
            phone_country: $request->phone_country,
            customer: $request->user()->customer
        );

        return redirect()->route('contacts.index')
            ->flashMessage('Contacto guardado exitosamente.');
    }

    public function edit(EditContactRequest $request, Contact $contact)
    {
        return Inertia::render('Contacts', [
            'contact' => $contact,
            'contacts' => $request->user()->customer->contacts,
            'genders' => Gender::casesWithLabels(),
        ]);
    }

    public function update(UpdateContactRequest $request, Contact $contact, UpdateContactAction $action)
    {
        $action(
            name: $request->name,
            paternal_lastname: $request->paternal_lastname,
            maternal_lastname: $request->maternal_lastname,
            birth_date: Carbon::parse($request->birth_date),
            gender: Gender::from($request->gender),
            phone: $request->phone,
            phone_country: $request->phone_country,
            contact: $contact
        );

        return redirect()->route('contacts.index')
            ->flashMessage('Contacto actualizado exitosamente.');
    }

    public function destroy(DestroyContactRequest $request, Contact $contact, DestroyContactAction $action)
    {
        $action($contact);

        return redirect()->route('contacts.index')
            ->flashMessage('Contacto eliminado exitosamente.');
    }
}
