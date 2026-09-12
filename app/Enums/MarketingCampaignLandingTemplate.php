<?php

namespace App\Enums;

enum MarketingCampaignLandingTemplate: string
{
    case Conversion = 'conversion';
    case Editorial = 'editorial';
    case Catalog = 'catalog';

    public function label(): string
    {
        return match ($this) {
            self::Conversion => 'Conversión',
            self::Editorial => 'Editorial',
            self::Catalog => 'Catálogo',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Conversion => 'Prioriza la acción rápida con hero, beneficios, CTAs y productos destacados.',
            self::Editorial => 'Da más peso a la historia, explicación de campaña e imágenes antes del catálogo.',
            self::Catalog => 'Muestra los estudios desde arriba para campañas con muchos productos.',
        };
    }

    public function recommendation(): string
    {
        return match ($this) {
            self::Conversion => 'Recomendada para campañas pagadas y promociones con pocos productos.',
            self::Editorial => 'Recomendada cuando necesitas contexto, educación o confianza antes de vender.',
            self::Catalog => 'Recomendada para colecciones amplias o campañas por marca/categoría.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string, recommendation: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $template) => [
                'value' => $template->value,
                'label' => $template->label(),
                'description' => $template->description(),
                'recommendation' => $template->recommendation(),
            ],
            self::cases(),
        );
    }
}
