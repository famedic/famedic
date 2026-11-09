<?php

namespace App\Models;

/** Internal registry record; never mass-assign a public request. No enrollment endpoint. */
final class ApiConsumerDelegation extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = ['*'];

    protected $casts = ['capabilities' => 'array', 'enabled' => 'boolean', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
}
