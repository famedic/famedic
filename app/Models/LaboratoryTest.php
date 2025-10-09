<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\LengthAwarePaginator;
use Laravel\Scout\Searchable;

class LaboratoryTest extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'formatted_public_price',
        'formatted_famedic_price',
    ];

    protected function casts(): array
    {
        return [
            'requires_appointment' => 'boolean',
            'brand' => LaboratoryBrand::class,
            'feature_list' => 'array',
        ];
    }

    public function toSearchableArray(): array
    {
        return [
            'category' => $this->laboratoryTestCategory->name,
            'name' => $this->name,
            'other_name' => $this->other_name,
            'elements' => $this->elements,
            'common_use' => $this->common_use,
            'brand' => $this->brand,
            'gda_id' => $this->gda_id,
        ];
    }

    public static function algoliaFilter(
        LaboratoryBrand $brand,
        ?string $searchQuery = null,
        ?LaboratoryTestCategory $laboratoryTestCategory = null
    ): LengthAwarePaginator {
        $algoliaQuery = self::search($searchQuery ?? '');
        $algoliaFiltersString = "brand:{$brand->value}";

        if ($laboratoryTestCategory) {
            $algoliaFiltersString .= " AND category:'{$laboratoryTestCategory->name}'";
        }

        $algoliaQuery->with([
            'filters' => $algoliaFiltersString,
        ]);

        return $algoliaQuery->paginate();
    }

    protected function famedicPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => numberCents($this->famedic_price_cents)
        );
    }

    protected function formattedPublicPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => formattedCentsPrice($this->public_price_cents)
        );
    }

    protected function formattedFamedicPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => formattedCentsPrice($this->famedic_price_cents)
        );
    }

    public function laboratoryTestCategory(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTestCategory::class);
    }

    public function scopeOfBrand(Builder $query, LaboratoryBrand $brand): void
    {
        $query->where('brand', $brand->value);
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('other_name', 'like', "%{$search}%")
                        ->orWhere('gda_id', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('elements', 'like', "%{$search}%")
                        ->orWhere('common_use', 'like', "%{$search}%")
                        ->orWhere('indications', 'like', "%{$search}%")
                        ->orWhereHas('laboratoryTestCategory', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($filters['brand'] ?? null, function ($query, $brand) {
                $query->ofBrand(LaboratoryBrand::from($brand));
            })
            ->when($filters['category'] ?? null, function ($query, $categoryId) {
                $query->where('laboratory_test_category_id', $categoryId);
            })
            ->when(isset($filters['requires_appointment']) && $filters['requires_appointment'] !== '', function ($query) use ($filters) {
                if ($filters['requires_appointment'] === 'required') {
                    $query->where('requires_appointment', true);
                }

                if ($filters['requires_appointment'] === 'not_required') {
                    $query->where('requires_appointment', false);
                }
            });
    }
}
