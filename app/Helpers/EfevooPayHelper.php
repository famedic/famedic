<?php

namespace App\Helpers;

use App\Facades\EfevooPay as EfevooPayFacade;

if (!function_exists('EfevooPay')) {
    function EfevooPay()
    {
        return EfevooPayFacade::getFacadeRoot();
    }
}