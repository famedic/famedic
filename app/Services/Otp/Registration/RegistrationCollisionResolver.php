<?php

namespace App\Services\Otp\Registration;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read-only collision classifier for future secure registration (P0-A5.2).
 *
 * Does not acquire locks, create rows, or map kinds to public HTTP codes.
 * Phone uniqueness is application-level only (no DB UNIQUE) — duplicate phones
 * across users are reported as PHONE_EXISTS / CONTACTS_BELONG_TO_DIFFERENT_USERS /
 * AMBIGUOUS_PHONE when multiple distinct nationals match loosely.
 */
final class RegistrationCollisionResolver
{
    public function __construct(
        private readonly EmailNormalizer $emailNormalizer,
        private readonly MexicoPhoneNormalizer $phoneNormalizer,
    ) {
    }

    public function resolve(
        NormalizedEmail $email,
        PhoneIdentity $phone,
    ): RegistrationCollisionResult {
        $emailUsers = $this->findUsersByEmail($email);
        $phoneUsers = $this->findUsersByPhone($phone);

        if ($phoneUsers->count() > 1) {
            // Historical duplicates sharing the same national key.
            $emailIds = $emailUsers->pluck('id')->all();
            $phoneIds = $phoneUsers->pluck('id')->all();
            $intersection = array_values(array_intersect($emailIds, $phoneIds));

            if ($emailUsers->isNotEmpty() && $intersection === [] && $emailIds !== []) {
                return new RegistrationCollisionResult(
                    RegistrationCollisionKind::ContactsBelongToDifferentUsers,
                    array_values(array_unique([...$emailIds, ...$phoneIds])),
                );
            }

            return new RegistrationCollisionResult(
                RegistrationCollisionKind::AmbiguousPhone,
                $phoneIds,
            );
        }

        $emailUser = $emailUsers->first();
        $phoneUser = $phoneUsers->first();

        if ($emailUser && $phoneUser) {
            if ((int) $emailUser->id === (int) $phoneUser->id) {
                return new RegistrationCollisionResult(
                    RegistrationCollisionKind::BothSameUser,
                    [(int) $emailUser->id],
                );
            }

            return new RegistrationCollisionResult(
                RegistrationCollisionKind::ContactsBelongToDifferentUsers,
                [(int) $emailUser->id, (int) $phoneUser->id],
            );
        }

        if ($emailUser) {
            return new RegistrationCollisionResult(
                RegistrationCollisionKind::EmailExists,
                [(int) $emailUser->id],
            );
        }

        if ($phoneUser) {
            return new RegistrationCollisionResult(
                RegistrationCollisionKind::PhoneExists,
                [(int) $phoneUser->id],
            );
        }

        return new RegistrationCollisionResult(RegistrationCollisionKind::Available);
    }

    /**
     * @throws \App\Exceptions\Otp\OtpIdentityNormalizationException
     */
    public function resolveFromRaw(string $email, string $phone, ?string $phoneCountry = null): RegistrationCollisionResult
    {
        return $this->resolve(
            $this->emailNormalizer->normalize($email),
            $this->phoneNormalizer->normalize($phone, $phoneCountry),
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function findUsersByEmail(NormalizedEmail $email): Collection
    {
        $value = $email->value();

        // Case-insensitive match consistent with lowercase storage + ci collations.
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$value])
            ->get();
    }

    /**
     * Match stored national phone (+ optional legacy trunk variants) for MX.
     *
     * @return Collection<int, User>
     */
    private function findUsersByPhone(PhoneIdentity $phone): Collection
    {
        $national = $phone->nationalNumber();
        $country = $phone->countryCode();
        $legacyTrunk = '1'.$national;

        $query = User::query()
            ->where(function ($q) use ($national, $legacyTrunk) {
                $q->where('phone', $national)
                    ->orWhere('phone', $legacyTrunk)
                    ->orWhere('phone', '+52'.$national)
                    ->orWhere('phone', '+521'.$national)
                    ->orWhere('phone', '52'.$national)
                    ->orWhere('phone', '521'.$national);
            });

        // Prefer same country when set; also include NULL country rows with same national
        // to avoid false negatives on incomplete historical data.
        $query->where(function ($q) use ($country) {
            $q->where('phone_country', $country)
                ->orWhereNull('phone_country')
                ->orWhere('phone_country', '');
        });

        return $query->get();
    }
}
