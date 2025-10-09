<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentationAcceptController extends Controller
{
    public function index()
    {
        return Inertia::render('Auth/DocumentationAccept');
    }

    public function store(Request $request)
    {
        $request->user()->update([
            'documentation_accepted_at' => now(),
        ]);

        return redirect()->route('home');
    }
}
