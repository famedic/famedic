<?php

namespace App\Actions\Contacts;

use App\Models\Contact;

class DestroyContactAction
{
    public function __invoke(
        Contact $contact
    ): void {
        $contact->delete();
    }
}
