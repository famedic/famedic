<?php

namespace App\Services\Api\V1\Auth;

use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use Illuminate\Http\Request;

/** Credential transport only; RegisteredMachinePatBackendAuthenticator verifies identity. */
final class TlsMachinePatCredentialSource implements BackendCredentialSource
{
    public function credentialFor(Request $request): ?string
    {
        if (config('akubica_connectors.transport.enabled') !== true || config('akubica_connectors.transport.certified') !== true || ! $request->isSecure()) {
            return null;
        }
        $values = $request->headers->all('X-Consumer-Credential');
        if (count($values) !== 1 || ! is_string($values[0]) || ! preg_match('/\A[1-9][0-9]*\|[A-Za-z0-9]{40,128}\z/D', $values[0])) {
            return null;
        }

        return $values[0];
    }
}
