<?php

namespace App\Exports;

use App\Exports\Carts\CartItemsSheet;
use App\Exports\Carts\CartsSheet;
use App\Exports\Carts\CartsSummarySheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class CartsExport implements WithMultipleSheets
{
    public array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = self::normalizeFilters($filters);
    }

    public static function normalizeFilters(array $filters): array
    {
        $filters = collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->all();

        if (empty($filters['start_date']) && empty($filters['end_date'])) {
            $today = now('America/Monterrey');
            $filters['start_date'] = $today->copy()->subDays(6)->toDateString();
            $filters['end_date'] = $today->toDateString();
            $filters['_using_default_period'] = true;
        } else {
            $filters['_using_default_period'] = false;
        }

        return $filters;
    }

    public static function fileDateSegment(array $filters): string
    {
        $filters = self::normalizeFilters($filters);

        return ($filters['start_date'] ?? now('America/Monterrey')->toDateString())
            .'-a-'
            .($filters['end_date'] ?? now('America/Monterrey')->toDateString());
    }

    public function sheets(): array
    {
        return [
            new CartsSheet($this->filters),
            new CartItemsSheet($this->filters),
            new CartsSummarySheet($this->filters),
        ];
    }
}
