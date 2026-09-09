<?php

namespace App\Console\Commands;

use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\User;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use Illuminate\Console\Command;

class RepairRegisterSmsPhoneVerificationCommand extends Command
{
    protected $signature = 'otp:repair-register-sms-phone-verification
        {--user-id= : User id to inspect or repair}
        {--apply : Apply the repair. Defaults to dry-run.}';

    protected $description = 'Dry-run by default repair for users created by verified registration SMS without phone_verified_at.';

    public function handle(MexicoPhoneNormalizer $normalizer): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $userId = $this->option('user-id');
        if (! is_numeric($userId)) {
            $this->error('Provide exactly one --user-id.');

            return self::FAILURE;
        }

        $user = User::query()->with('customer')->find((int) $userId);
        if (! $user) {
            $this->line('mode='.$this->mode());
            $this->line('user_id='.(int) $userId);
            $this->line('eligible=false');
            $this->line('reason=user_not_found');

            return self::FAILURE;
        }

        $phone = $this->normalizeUserPhone($normalizer, $user);
        $masked = $this->maskPhone($phone);
        $evidence = $phone !== null ? $this->smsRegistrationEvidence($phone) : collect();

        $reason = $this->ineligibilityReason($user, $phone, $evidence->count());
        $eligible = $reason === null;

        $this->line('mode='.$this->mode());
        $this->line('user_id='.$user->id);
        $this->line('phone_masked='.$masked);
        $this->line('eligible='.($eligible ? 'true' : 'false'));

        if (! $eligible) {
            $this->line('reason='.$reason);

            return self::SUCCESS;
        }

        $challenge = $evidence->first();
        $operation = $challenge->deliveryOperation;
        $this->line('challenge_row_id='.$challenge->id);
        $this->line('operation_id='.$operation->id);
        $this->line('effective_channel=sms');

        if (! $this->option('apply')) {
            $this->line('changed=false');

            return self::SUCCESS;
        }

        $user->forceFill([
            'phone_verified_at' => $challenge->consumed_at ?? now(),
        ])->save();

        $this->line('changed=true');

        return self::SUCCESS;
    }

    private function mode(): string
    {
        return $this->option('apply') ? 'apply' : 'dry-run';
    }

    private function normalizeUserPhone(MexicoPhoneNormalizer $normalizer, User $user): ?string
    {
        try {
            return $normalizer
                ->normalize((string) $user->phone, (string) ($user->phone_country ?: 'MX'))
                ->e164();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, OtpChallenge>
     */
    private function smsRegistrationEvidence(string $phoneE164): \Illuminate\Support\Collection
    {
        return OtpChallenge::query()
            ->where('purpose', P0aOtpPurpose::AkubicaRegister->value)
            ->where('channel', P0aOtpChannel::Sms->value)
            ->where('destination_normalized', $phoneE164)
            ->whereNotNull('consumed_at')
            ->whereNull('invalidated_at')
            ->whereHas('registrationIntent', function ($query): void {
                $query->where('status', 'CONSUMED')
                    ->whereNotNull('consumed_at')
                    ->whereNull('encrypted_payload');
            })
            ->whereHas('deliveryOperation', function ($query): void {
                $query->where('primary_channel', 'sms')
                    ->where('fallback_used', false)
                    ->where('result_class', OtpDeliveryResultClass::Accepted->value)
                    ->where('status', 'sms_accepted');
            })
            ->with(['deliveryOperation' => function ($query): void {
                $query->where('primary_channel', 'sms')
                    ->where('fallback_used', false)
                    ->where('result_class', OtpDeliveryResultClass::Accepted->value)
                    ->where('status', 'sms_accepted')
                    ->latest('id');
            }])
            ->latest('id')
            ->get();
    }

    private function ineligibilityReason(User $user, ?string $phoneE164, int $evidenceCount): ?string
    {
        if ($phoneE164 === null) {
            return 'phone_format_mismatch';
        }

        if ($user->phone_verified_at !== null) {
            return 'already_verified';
        }

        if ($user->customer === null) {
            return 'customer_missing';
        }

        if ($evidenceCount === 0) {
            return 'sms_registration_evidence_missing';
        }

        if ($evidenceCount > 1) {
            return 'ambiguous_match';
        }

        return null;
    }

    private function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits === '' ? '***' : '***'.substr($digits, -4);
    }
}
