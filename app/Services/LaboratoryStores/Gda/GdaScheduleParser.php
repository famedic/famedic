<?php

namespace App\Services\LaboratoryStores\Gda;

use Illuminate\Support\Str;

class GdaScheduleParser
{
    public function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        $days = [];
        $warnings = [];

        for ($day = 1; $day <= 7; $day++) {
            $days[$day] = [
                'day_of_week' => $day,
                'opens_at' => null,
                'closes_at' => null,
                'is_closed' => false,
                'raw_text' => $raw,
            ];
        }

        if ($raw === '') {
            return ['days' => $days, 'warnings' => ['empty schedule']];
        }

        if (preg_match('/^(N\/A|NA|CERRADO|SIN SERVICIO)$/i', Str::ascii($raw)) === 1) {
            foreach (array_keys($days) as $day) {
                $days[$day]['is_closed'] = true;
            }

            return ['days' => $days, 'warnings' => []];
        }

        $touchedDays = [];

        foreach (preg_split('/\s*(?:\/\/|;)\s*/', $raw) ?: [] as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $targetDays = $this->daysForSegment($segment);
            $asciiSegment = Str::ascii($segment);

            if ($targetDays === []) {
                if (preg_match('/^(N\/A|NA|CERRADO|SIN SERVICIO)$/i', $asciiSegment) === 1) {
                    $targetDays = array_values(array_diff([1, 2, 3, 4, 5, 6, 7], $touchedDays));
                } elseif (preg_match('/\d{1,2}:\d{2}\s*(?:A\s*<?|-)\s*\d{1,2}:\d{2}/i', $segment) === 1) {
                    $remainingDays = array_values(array_diff([1, 2, 3, 4, 5, 6, 7], $touchedDays));
                    $targetDays = $remainingDays === [] ? [] : [min($remainingDays)];
                }

                if ($targetDays === []) {
                    $warnings[] = "Unrecognized schedule days: {$segment}";

                    continue;
                }
            }

            $isClosed = preg_match('/\b(N\/A|NA|CERRADO|SIN SERVICIO)\b/i', $asciiSegment) === 1;
            preg_match('/(\d{1,2}:\d{2})\s*(?:A\s*<?|-)\s*(\d{1,2}:\d{2})/i', $segment, $matches);

            if (! $isClosed && $matches === []) {
                $warnings[] = "Unrecognized schedule hours: {$segment}";

                continue;
            }

            foreach ($targetDays as $day) {
                $days[$day] = [
                    'day_of_week' => $day,
                    'opens_at' => $isClosed ? null : $this->normalizeTime($matches[1]),
                    'closes_at' => $isClosed ? null : $this->normalizeTime($matches[2]),
                    'is_closed' => $isClosed,
                    'raw_text' => $segment,
                ];
                $touchedDays[] = $day;
            }
        }

        return ['days' => $days, 'warnings' => $warnings];
    }

    private function daysForSegment(string $segment): array
    {
        $segment = Str::upper(Str::ascii($segment));

        if (str_contains($segment, 'LUNES A VIERNES') || str_contains($segment, 'LUNES-VIERNES')) {
            return [1, 2, 3, 4, 5];
        }

        if (str_contains($segment, 'LUNES A SABADO') || str_contains($segment, 'LUNES A SABADOS')) {
            return [1, 2, 3, 4, 5, 6];
        }

        if (str_contains($segment, 'LUNES A DOMINGO')) {
            return [1, 2, 3, 4, 5, 6, 7];
        }

        if (str_contains($segment, 'SABADO')) {
            return [6];
        }

        if (str_contains($segment, 'DOMINGO')) {
            return [7];
        }

        if (str_contains($segment, 'LUNES')) {
            return [1];
        }

        return [];
    }

    private function normalizeTime(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return sprintf('%02d:%02d:00', $hour, $minute);
    }
}
