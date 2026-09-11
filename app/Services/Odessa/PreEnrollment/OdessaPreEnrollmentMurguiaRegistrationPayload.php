<?php

namespace App\Services\Odessa\PreEnrollment;

use App\Models\OdessaPreEnrollment;
use Carbon\CarbonImmutable;
use Throwable;

class OdessaPreEnrollmentMurguiaRegistrationPayload
{
    public function __construct(
        public readonly string $product,
        public readonly string $subproduct,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
    ) {}

    public static function fromConfig(): array
    {
        $product = trim((string) config('famedic.odessa_pre_enrollments.murguia_product', ''));
        $subproduct = trim((string) config('famedic.odessa_pre_enrollments.murguia_subproduct', ''));
        $startsAt = self::parseDate(config('famedic.odessa_pre_enrollments.membership_starts_at'));
        $endsAt = self::parseDate(config('famedic.odessa_pre_enrollments.membership_ends_at'));

        if ($product === '' || $subproduct === '' || ! $startsAt || ! $endsAt) {
            return ['ok' => false, 'code' => 'MURGUIA_CONTRACT_NOT_CONFIGURED'];
        }

        if ($startsAt->greaterThanOrEqualTo($endsAt) || $endsAt->endOfDay()->isPast()) {
            return ['ok' => false, 'code' => 'MURGUIA_CONTRACT_NOT_CONFIGURED'];
        }

        return [
            'ok' => true,
            'contract' => new self($product, $subproduct, $startsAt, $endsAt),
        ];
    }

    public function datesMatch(OdessaPreEnrollment $preEnrollment): bool
    {
        return (! $preEnrollment->membership_start_date || $preEnrollment->membership_start_date->toDateString() === $this->startsAt->toDateString())
            && (! $preEnrollment->membership_end_date || $preEnrollment->membership_end_date->toDateString() === $this->endsAt->toDateString());
    }

    public function applyDates(OdessaPreEnrollment $preEnrollment): void
    {
        $preEnrollment->forceFill([
            'membership_start_date' => $this->startsAt->toDateString(),
            'membership_end_date' => $this->endsAt->toDateString(),
        ]);
    }

    public function toArray(OdessaPreEnrollment $preEnrollment): array
    {
        return [
            'noCredito' => (string) $preEnrollment->medical_attention_identifier,
            'nombre' => trim(implode(' ', array_filter([
                $preEnrollment->first_name,
                $preEnrollment->paternal_last_name,
                $preEnrollment->maternal_last_name,
            ]))),
            'campaña' => 'Famedic',
            'producto' => $this->product,
            'subProducto' => $this->subproduct,
            'inicioVigencia' => $this->startsAt->format('d-m-Y'),
            'finVigencia' => $this->endsAt->format('d-m-Y'),
        ];
    }

    public static function isConfigured(): bool
    {
        return (bool) (self::fromConfig()['ok'] ?? false);
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date->startOfDay() : null;
    }
}
