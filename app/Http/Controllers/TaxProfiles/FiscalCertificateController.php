<?php

namespace App\Http\Controllers\TaxProfiles;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaxProfiles\ShowTaxProfileRequest;
use App\Models\TaxProfile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class FiscalCertificateController extends Controller
{
    public function __invoke(ShowTaxProfileRequest $request, TaxProfile $taxProfile)
    {
        return Inertia::location(
            Storage::temporaryUrl(
                $taxProfile->fiscal_certificate,
                now()->addMinutes(5)
            )
        );
    }
}
