<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Authority provisioned by trusted administration, never by OTP input. */
final class RegistryOtpEnrollmentOperator implements OtpEnrollmentOperator
{
    public function operatorFor(AuthenticatedBackendClient $client, User $verifiedUser): ?User
    {
        if (config('akubica_connectors.otp_enrollment.enabled') !== true || config('akubica_connectors.otp_enrollment.certified') !== true) {
            return null;
        }
        $row = DB::table('api_consumer_otp_issuers')->where('consumer_id', $client->consumerId)->where('channel_id', $client->channelId)->where('enabled', true)->where('expires_at', '>', now())->lockForUpdate()->first();
        if (! $row || ! $row->approval_ref) {
            return null;
        }
        $operator = User::find($row->operator_id);

        return $operator && Gate::forUser($operator)->allows('manageApiConsumerRegistry') ? $operator : null;
    }
}
