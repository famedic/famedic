<?php

namespace App\Contracts\Api\V1\Auth;

use App\Services\Api\V1\Auth\AuthenticatedBackendClient;
use Illuminate\Http\Request;

interface BackendClientAuthenticator
{
    /** Verify registered backend credentials using the approved transport.
     * Implementations must not trust forwarded headers without authenticated ingress.
     * No implementation or credential issuance is activated by this interface.
     */
    public function authenticate(Request $request): ?AuthenticatedBackendClient;
}
