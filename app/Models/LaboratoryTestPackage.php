<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LaboratoryTestPackage extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function packageTest(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTest::class, 'package_test_id');
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(
            LaboratoryTest::class,
            'laboratory_test_package_components',
            'package_id',
            'component_test_id',
        )->withPivot('sort_order')->orderBy('laboratory_test_package_components.sort_order');
    }
}
