<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $guarded = [];

    public function certificateAccounts()
    {
        return $this->morphMany(CertificateAccount::class, 'companyable');
    }
}
