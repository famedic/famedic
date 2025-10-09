<?php

namespace App\Enums;

use App\Contracts\LabelledEnum;

enum LaboratoryBrand: string implements LabelledEnum
{
    case OLAB = 'olab';
    case SWISSLAB = 'swisslab';
    case JENNER = 'jenner';
    case LIACSA = 'liacsa';
    case AZTECA = 'azteca';

    public function label(): string
    {
        return match ($this) {
            self::SWISSLAB => 'Swisslab',
            self::LIACSA => 'Liacsa',
            self::AZTECA => 'Azteca',
            self::JENNER => 'Jenner',
            self::OLAB => 'Olab',
        };
    }

    public function states(): array
    {
        return match ($this) {
            self::SWISSLAB => ['Nuevo León'],
            self::LIACSA => ['Chihuahua'],
            self::AZTECA => ['Ciudad de México', 'Estado de México'],
            self::JENNER => ['Ciudad de México', 'Estado de México'],
            self::OLAB => ['Ciudad de México', 'Estado de México'],
        };
    }

    public function imageSrc(): string
    {
        return match ($this) {
            self::SWISSLAB => 'GDA-SWISSLAB.png',
            self::LIACSA => 'GDA-LIACSA.png',
            self::AZTECA => 'GDA-AZTECA.png',
            self::JENNER => 'GDA-JENNER.png',
            self::OLAB => 'GDA-OLAB.png',
        };
    }

    public static function brandData(LaboratoryBrand $brand): array
    {
        return [
            'value' => $brand->value,
            'name' => $brand->label(),
            'states' => $brand->states(),
            'imageSrc' => $brand->imageSrc(),
        ];
    }

    public static function brandsData(): array
    {
        return array_combine(
            array_map(fn($brand) => $brand->value, self::cases()),
            array_map(fn($brand) => [
                'name' => $brand->label(),
                'states' => $brand->states(),
                'imageSrc' => $brand->imageSrc(),
            ], LaboratoryBrand::cases())
        );
    }
}
