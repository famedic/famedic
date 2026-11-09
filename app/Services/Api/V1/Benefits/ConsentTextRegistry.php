<?php

namespace App\Services\Api\V1\Benefits;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ConsentTextRegistry
{
    public function register(User $operator, string $version, string $text, string $approval): void
    {
        Gate::forUser($operator)->authorize('manageBenefitConsentText');
        if (! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $version) || ! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $approval) || trim($text) === '' || strlen($text) > 8000) {
            throw new \DomainException('Invalid consent text');
        }
        DB::transaction(function () use ($operator, $version, $text, $approval) {
            $row = DB::table('benefit_consent_texts')->where('version', $version)->lockForUpdate()->first();
            $digest = hash('sha256', $text);
            if ($row) {
                if (! hash_equals($row->digest, $digest)) {
                    throw new \DomainException('Consent text version is immutable');
                }

return;
            }
            DB::table('benefit_consent_texts')->insert(['version' => $version, 'text' => $text, 'digest' => $digest, 'enabled' => false, 'operator_id' => $operator->id, 'approval_ref' => $approval, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function activate(User $operator, string $version, bool $enabled): void
    {
        Gate::forUser($operator)->authorize('manageBenefitConsentText');
        if (DB::table('benefit_consent_texts')->where('version', $version)->update(['enabled' => $enabled, 'updated_at' => now()]) !== 1) {
            throw new \DomainException('Consent text not available');
        }
    }

    public function active(?string $version): ?object
    {
        if (! $version) {
            return null;
        }$row = DB::table('benefit_consent_texts')->where('version', $version)->where('enabled', true)->first();

        return $row && hash_equals($row->digest, hash('sha256', $row->text)) ? $row : null;
    }
}
