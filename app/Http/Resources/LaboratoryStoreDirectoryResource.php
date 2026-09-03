<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LaboratoryStoreDirectoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $services = $this->relationLoaded('services')
            ? $this->services->where('is_active', true)
            : collect();

        return [
            'id' => $this->id,
            'brand' => $this->brand?->value ?? $this->brand,
            'name' => $this->name,
            'address' => $this->address,
            'street' => $this->street,
            'exterior_number' => $this->exterior_number,
            'interior_number' => $this->interior_number,
            'neighborhood' => $this->neighborhood,
            'municipality' => $this->municipality,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'distance_km' => $this->when(
                array_key_exists('distance_km', $this->resource->getAttributes()) && $this->distance_km !== null,
                fn () => round((float) $this->distance_km, 1),
            ),
            'google_maps_url' => $this->google_maps_url,
            'weekly_hours' => $this->weekly_hours,
            'saturday_hours' => $this->saturday_hours,
            'sunday_hours' => $this->sunday_hours,
            'today' => $this->todayHours(),
            'weekly_schedule' => $this->weeklySchedule(),
            'capabilities' => $this->whenLoaded('capabilities', fn () => $this->capabilities
                ->where('is_active', true)
                ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
                ->map(fn ($capability) => [
                    'slug' => $capability->slug,
                    'name' => $capability->name,
                ])
                ->values()
                ->all()),
            'services' => $this->whenLoaded('services', fn () => $services
                ->map(fn ($service) => [
                    'type' => $this->publicServiceType($service->service_type),
                    'name' => $service->name,
                ])
                ->values()
                ->all()),
            'service_flags' => [
                'has_clinical_history' => $services->contains('service_type', 'clinical_history'),
                'has_optical' => $services->contains('service_type', 'optical'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function todayHours(): array
    {
        if (! $this->relationLoaded('hours')) {
            return [
                'is_closed' => null,
                'opens_at' => null,
                'closes_at' => null,
                'label' => null,
                'status' => 'unavailable',
                'minutes_until_close' => null,
                'day_of_week' => null,
            ];
        }

        $now = CarbonImmutable::now('America/Mexico_City');
        $dayOfWeek = $now->dayOfWeekIso;
        $hours = $this->hours->firstWhere('day_of_week', $dayOfWeek);

        if ($hours === null) {
            return [
                'is_closed' => null,
                'opens_at' => null,
                'closes_at' => null,
                'label' => null,
                'status' => 'unavailable',
                'minutes_until_close' => null,
                'day_of_week' => $dayOfWeek,
            ];
        }

        $opensAt = $this->formatTime($hours->opens_at);
        $closesAt = $this->formatTime($hours->closes_at);
        $isClosed = (bool) $hours->is_closed;
        $status = $isClosed ? 'closed' : 'unavailable';
        $minutesUntilClose = null;

        if (! $isClosed && $opensAt !== null && $closesAt !== null) {
            $opensAtToday = CarbonImmutable::createFromFormat('Y-m-d H:i', $now->toDateString().' '.$opensAt, 'America/Mexico_City');
            $closesAtToday = CarbonImmutable::createFromFormat('Y-m-d H:i', $now->toDateString().' '.$closesAt, 'America/Mexico_City');

            if ($closesAtToday->lessThanOrEqualTo($opensAtToday)) {
                $closesAtToday = $closesAtToday->addDay();
            }

            if ($now->lessThan($opensAtToday)) {
                $status = 'opens_later';
            } elseif ($now->lessThan($closesAtToday)) {
                $status = 'open';
                $minutesUntilClose = (int) ceil($now->diffInSeconds($closesAtToday) / 60);
            } else {
                $status = 'closed';
            }
        }

        return [
            'is_closed' => $isClosed,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'label' => $isClosed ? 'Cerrado' : trim($opensAt.' - '.$closesAt),
            'status' => $status,
            'minutes_until_close' => $minutesUntilClose,
            'day_of_week' => $dayOfWeek,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function weeklySchedule(): array
    {
        if (! $this->relationLoaded('hours')) {
            return [];
        }

        return collect(range(1, 7))
            ->map(function (int $dayOfWeek) {
                $hours = $this->hours->firstWhere('day_of_week', $dayOfWeek);

                if ($hours === null) {
                    return [
                        'day_of_week' => $dayOfWeek,
                        'label' => $this->dayLabel($dayOfWeek),
                        'is_closed' => null,
                        'opens_at' => null,
                        'closes_at' => null,
                    ];
                }

                return [
                    'day_of_week' => $dayOfWeek,
                    'label' => $this->dayLabel($dayOfWeek),
                    'is_closed' => (bool) $hours->is_closed,
                    'opens_at' => $this->formatTime($hours->opens_at),
                    'closes_at' => $this->formatTime($hours->closes_at),
                ];
            })
            ->values()
            ->all();
    }

    private function formatTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }

    private function publicServiceType(string $type): string
    {
        return match ($type) {
            'clinical_history' => 'historia_clinica',
            'optical' => 'optica',
            default => $type,
        };
    }

    private function dayLabel(int $dayOfWeek): string
    {
        return match ($dayOfWeek) {
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo',
            default => '',
        };
    }
}
