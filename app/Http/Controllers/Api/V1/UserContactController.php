<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Contacts\CreateContactAction;
use App\Actions\Contacts\DestroyContactAction;
use App\Actions\Contacts\UpdateContactAction;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\StoreContactRequest;
use App\Http\Requests\Api\V1\User\UpdateContactRequest;
use App\Http\Resources\Api\V1\ContactResource;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contacts = $request->user()->customer
            ->contacts()
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'contacts' => ContactResource::collection($contacts)->resolve($request),
        ]);
    }

    public function store(
        StoreContactRequest $request,
        CreateContactAction $createContactAction,
    ): JsonResponse {
        $contact = $createContactAction(
            name: $request->validated('name'),
            paternal_lastname: $request->validated('paternal_lastname'),
            maternal_lastname: $request->validated('maternal_lastname'),
            birth_date: Carbon::parse($request->validated('birth_date')),
            gender: Gender::from($request->validated('gender')),
            phone: $request->validated('phone'),
            phone_country: $request->validated('phone_country'),
            customer: $request->user()->customer,
        );

        return ApiResponse::success([
            'contact' => (new ContactResource($contact))->resolve($request),
        ], status: 201);
    }

    public function update(
        UpdateContactRequest $request,
        int $contactId,
        UpdateContactAction $updateContactAction,
    ): JsonResponse {
        $contact = $this->findOwnedContact($request, $contactId);

        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $contact = $updateContactAction(
            name: $request->validated('name'),
            paternal_lastname: $request->validated('paternal_lastname'),
            maternal_lastname: $request->validated('maternal_lastname'),
            birth_date: Carbon::parse($request->validated('birth_date')),
            gender: Gender::from($request->validated('gender')),
            phone: $request->validated('phone'),
            phone_country: $request->validated('phone_country'),
            contact: $contact,
        );

        return ApiResponse::success([
            'contact' => (new ContactResource($contact))->resolve($request),
        ]);
    }

    public function destroy(
        Request $request,
        int $contactId,
        DestroyContactAction $destroyContactAction,
    ): JsonResponse {
        $contact = $this->findOwnedContact($request, $contactId);

        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $destroyContactAction($contact);

        return ApiResponse::success(['deleted' => true]);
    }

    private function findOwnedContact(Request $request, int $contactId): Contact|JsonResponse
    {
        $contact = Contact::query()
            ->where('id', $contactId)
            ->where('customer_id', $request->user()->customer->id)
            ->first();

        if (! $contact) {
            return ApiResponse::error(
                'CONTACT_NOT_FOUND',
                'El contacto no fue encontrado.',
                404,
            );
        }

        return $contact;
    }
}
