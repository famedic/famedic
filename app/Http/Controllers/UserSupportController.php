<?php

namespace App\Http\Controllers;

use App\Support\FamedicPublicContactConfig;
use Inertia\Inertia;
use Inertia\Response;

class UserSupportController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('User/Support', FamedicPublicContactConfig::supportPage());
    }
}
