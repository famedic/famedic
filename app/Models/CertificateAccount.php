<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificateAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'employee_metadata' => 'array',
    ];

    public function customer()
    {
        return $this->morphOne(Customer::class, 'customerable');
    }

    public function companyable()
    {
        return $this->morphTo();
    }
}
