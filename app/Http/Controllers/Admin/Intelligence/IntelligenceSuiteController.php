<?php

namespace App\Http\Controllers\Admin\Intelligence;

use App\Http\Controllers\Controller;
use App\Services\Intelligence\IntelligenceHubService;
use App\Support\Intelligence\IntelligenceSuiteCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntelligenceSuiteController extends Controller
{
    public function __construct(
        private IntelligenceHubService $hub,
    ) {
    }

    public function __invoke(Request $request, string $suite): Response
    {
        abort_unless(in_array($suite, IntelligenceSuiteCatalog::slugs(), true), 404);

        $administrator = $request->user()->administrator;
        abort_unless($administrator, 404);

        $payload = $this->hub->suiteFor($administrator, $suite);
        abort_unless($payload, 403);

        return Inertia::render('Admin/Intelligence/Suite', [
            'suite' => $payload,
            'meta' => [
                'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                'user_name' => $request->user()->name,
            ],
        ]);
    }
}
