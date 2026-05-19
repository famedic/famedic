<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaxProfileFiscalCertificateController extends Controller
{
    public function __invoke(Request $request, TaxProfile $taxProfile)
    {
        $request->user()->administrator->hasPermissionTo('tax-profiles.manage') || abort(403);

        $path = $taxProfile->fiscal_certificate;
        if (! $path) {
            abort(404);
        }

        $disk = $this->resolveDisk($path);
        if (! $disk) {
            abort(404);
        }

        $filename = 'constancia-fiscal-'.Str::slug($taxProfile->rfc ?: 'perfil').'.pdf';

        if ($request->boolean('download')) {
            return Storage::disk($disk)->download($path, $filename);
        }

        return Inertia::location(
            Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(5)),
        );
    }

    private function resolveDisk(string $path): ?string
    {
        foreach (array_filter(['local', 'private', config('filesystems.default')]) as $disk) {
            if (! is_string($disk) || $disk === '') {
                continue;
            }

            if (config("filesystems.disks.{$disk}") && Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return Storage::exists($path) ? config('filesystems.default') : null;
    }
}
