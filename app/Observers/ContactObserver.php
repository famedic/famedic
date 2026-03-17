<?php

namespace App\Observers;

use App\Models\Contact;
use App\Jobs\SendPatientCreatedToActiveCampaignJob;

class ContactObserver
{
    public function created(Contact $contact)
    {
        SendPatientCreatedToActiveCampaignJob::dispatch($contact);
    }
}