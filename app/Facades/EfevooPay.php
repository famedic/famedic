<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class EfevooPay extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'efevoopay';
    }
}