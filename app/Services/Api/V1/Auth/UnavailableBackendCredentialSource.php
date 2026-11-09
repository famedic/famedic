<?php

namespace App\Services\Api\V1\Auth;

use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use Illuminate\Http\Request;

/** Default: external dual-credential transport/enrollment is not approved. */
final class UnavailableBackendCredentialSource implements BackendCredentialSource
{
    public function credentialFor(Request $request): ?string
    {
        return null;
    }
}
