<?php

namespace App\Contracts\Api\V1\Auth;

use Illuminate\Http\Request;

interface BackendCredentialSource
{
    /** Only an explicitly approved transport adapter may supply this credential.
     * Do not use token names, public consumer headers, request bodies or sessions.
     */
    public function credentialFor(Request $request): ?string;
}
