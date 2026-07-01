<?php

namespace App\DTOs;

use App\Models\OdessaAfiliateAccount;

class SyncOdessaUserDataResult
{
    /**
     * @param  array<string, string|null>  $previousAttributes
     * @param  array<string, string|null>  $newAttributes
     */
    public function __construct(
        public OdessaAfiliateAccount $account,
        public OdessaUserData $userData,
        public array $previousAttributes,
        public array $newAttributes,
        public bool $persisted,
    ) {}

    public function hasChanges(): bool
    {
        return $this->previousAttributes !== $this->newAttributes;
    }
}
