<?php

namespace App\Http\Controllers;

use App\Models\Documentation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PrivacyPolicyController extends Controller
{
    public function __invoke(Request $request)
    {
        return Inertia::render('Document', [
            'markdown' => Documentation::sole()->privacy_policy,
            'name' => 'Política de privacidad',
        ]);
    }
}
