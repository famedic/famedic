<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtixSandboxNonce extends Model
{
    protected $table = 'atix_sandbox_nonces';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
