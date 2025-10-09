<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Documentation\IndexDocumentationRequest;
use App\Http\Requests\Admin\Documentation\UpdateDocumentationRequest;
use App\Models\Documentation;
use Inertia\Inertia;

class DocumentationController extends Controller
{
    public function index(IndexDocumentationRequest $request)
    {
        return Inertia::render('Admin/Documentation', [
            'documentation' => Documentation::first()
        ]);
    }

    public function update(UpdateDocumentationRequest $request)
    {
        $documentation = Documentation::firstOrCreate([]);

        if ($request->has('privacy_policy')) {
            $documentation->privacy_policy = $request->privacy_policy;
        }

        if ($request->has('terms_of_service')) {
            $documentation->terms_of_service = $request->terms_of_service;
        }

        $documentation->save();

        return redirect()->route('admin.documentation')
            ->flashMessage('Documentación guardada exitosamente.');
    }
}
