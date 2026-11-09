<?php

namespace Tests\Support\Api\V1\Identity;

use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use Illuminate\Http\Request;

/** Test transport ONLY. This header is not accepted by production code. The
 * supplied credential is verified cryptographically by real Sanctum/registry.
 */
final class LocalMachineCredentialSource implements BackendCredentialSource
{
    public function credentialFor(Request $request): ?string
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Test transport outside testing');
        }
        $value = $request->header('X-Synthetic-Machine-Credential');

        return is_string($value) ? $value : null;
    }
}
