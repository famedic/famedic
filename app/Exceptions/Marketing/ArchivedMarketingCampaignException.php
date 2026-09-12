<?php

namespace App\Exceptions\Marketing;

use Illuminate\Auth\Access\AuthorizationException;

class ArchivedMarketingCampaignException extends AuthorizationException
{
    public function __construct(string $message = 'La campaña archivada es de solo lectura.')
    {
        parent::__construct($message);
    }
}
