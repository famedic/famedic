<?php

namespace App\Support\Laboratory;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryQuote;
use Illuminate\Support\Str;

final class PatientEmailDetails
{
    /**
     * @return array{name: string, birth_date: string, gender: string|null, phone: string|null}
     */
    public static function fromPurchase(LaboratoryPurchase $purchase): array
    {
        return [
            'name' => self::fallback((string) $purchase->full_name, 'Paciente'),
            'birth_date' => self::fallback((string) $purchase->formatted_birth_date, '—'),
            'gender' => self::optional($purchase->formatted_gender),
            'phone' => self::optional($purchase->full_phone),
        ];
    }

    /**
     * @return array{name: string, birth_date: string, gender: string|null, phone: string|null}
     */
    public static function fromQuote(LaboratoryQuote $quote): array
    {
        return [
            'name' => self::fallback((string) $quote->patient_full_name, 'Paciente'),
            'birth_date' => self::fallback((string) $quote->formatted_patient_birth_date, '—'),
            'gender' => self::optional($quote->formatted_patient_gender),
            'phone' => self::optional($quote->patient_phone),
        ];
    }

    /**
     * @param  array{name: string, birth_date: string, gender: string|null, phone: string|null}  $details
     * @return list<string>
     */
    public static function mailLines(array $details, bool $includeExtendedDetails = true): array
    {
        $lines = [
            '**Datos del paciente**',
            '• Paciente: **'.$details['name'].'**',
            '• Fecha de nacimiento: **'.$details['birth_date'].'**',
        ];

        if ($includeExtendedDetails && $details['gender']) {
            $lines[] = '• Sexo: **'.$details['gender'].'**';
        }

        if ($includeExtendedDetails && $details['phone']) {
            $lines[] = '• Teléfono: **'.$details['phone'].'**';
        }

        return $lines;
    }

    /**
     * @param  array{name: string, birth_date: string, gender: string|null, phone: string|null}  $details
     */
    public static function isSameAsNotifiable(array $details, object $notifiable): bool
    {
        $patientName = self::normalizeName($details['name']);
        $notifiableName = self::normalizeName((string) ($notifiable->full_name ?? $notifiable->name ?? ''));

        return $patientName !== '' && $patientName === $notifiableName;
    }

    private static function normalizeName(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->squish()
            ->lower()
            ->toString();
    }

    private static function fallback(?string $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private static function optional(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && $value !== 'No especificado' ? $value : null;
    }
}
