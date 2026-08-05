<?php

namespace App\Http\Controllers\Admin\Intelligence;

use App\Http\Controllers\Controller;
use App\Services\Intelligence\IntelligenceHubService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntelligenceHubController extends Controller
{
    public function __construct(
        private IntelligenceHubService $hub,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $administrator = $request->user()->administrator;
        abort_unless($administrator, 404);

        $payload = $this->hub->buildFor($administrator, $request->user());

        abort_if($payload['suites'] === [], 403);

        $hour = (int) now('America/Monterrey')->format('G');
        $greeting = $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');

        return Inertia::render('Admin/Intelligence/Hub', [
            'suites' => $payload['suites'],
            'summary' => $payload['summary'],
            'quickActions' => $payload['quick_actions'],
            'searchIndex' => $payload['search_index'],
            'heroStats' => $payload['hero_stats'],
            'meta' => [
                'title' => 'Intelligence Hub',
                'subtitle' => 'Toda la inteligencia empresarial de Famedic en un solo lugar.',
                'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                'user_name' => $request->user()->name,
                'user_email' => $request->user()->email,
                'greeting' => $greeting,
            ],
        ]);
    }
}
