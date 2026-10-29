<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpStagingDiagnosticVault extends Model
{
    protected $fillable = [
        'challenge_public_id',
        'purpose',
        'code_ciphertext',
        'expires_at',
        'revealed_at',
    ];

    protected $hidden = [
        'code_ciphertext',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revealed_at' => 'datetime',
    ];
}
