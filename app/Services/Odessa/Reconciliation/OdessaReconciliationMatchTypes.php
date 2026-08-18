<?php

namespace App\Services\Odessa\Reconciliation;

class OdessaReconciliationMatchTypes
{
    public const CONFIRMED_ODESSA_ID = 'MATCH_CONFIRMED_ODESSA_ID';

    public const CONFIRMED_COMPANY_PARTNER = 'MATCH_CONFIRMED_COMPANY_PARTNER';

    public const CONFIRMED_MEMBERSHIP = 'MATCH_CONFIRMED_MEMBERSHIP';

    public const CONFIRMED_EMAIL = 'MATCH_CONFIRMED_EMAIL';

    public const PROBABLE_IDENTITY = 'MATCH_PROBABLE_IDENTITY';

    public const NONE = 'NO_MATCH';

    public const AMBIGUOUS = 'MATCH_AMBIGUOUS';

    public const DELETED = 'MATCH_DELETED_RECORD';
}
