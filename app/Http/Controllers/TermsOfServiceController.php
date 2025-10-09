<?php

namespace App\Http\Controllers;

use App\Models\Documentation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TermsOfServiceController extends Controller
{
    public function __invoke(Request $request)
    {
        return Inertia::render('Document', [
            'markdown' => Documentation::sole()->terms_of_service,
            'name' => 'Términos y condiciones de servicio',
        ]);
    }
}
