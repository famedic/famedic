<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OdessaAfiliatedCompany extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public function odessaAfiliateAccounts(): HasMany
    {
        return $this->hasMany(OdessaAfiliateAccount::class);
    }

    public function certificateAccounts()
    {
        return $this->morphMany(CertificateAccount::class, 'companyable');
    }
}
