<?php

namespace App\Http\Controllers\Admin\Workspace;

use App\Http\Controllers\Controller;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceHomeController extends Controller
{
    public function __construct(
        private WorkspaceService $workspace,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $administrator = $request->user()->administrator;
        abort_unless($administrator, 404);

        $payload = $this->workspace->buildFor($administrator, $request->user());
        abort_if($payload['workspaces'] === [], 403);

        $hour = (int) now('America/Monterrey')->format('G');
        $greeting = $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');

        return Inertia::render('Admin/Workspace/Home', [
            'workspaces' => $payload['workspaces'],
            'recommendations' => $payload['recommendations'],
            'quickActions' => $payload['quick_actions'],
            'searchIndex' => $payload['search_index'],
            'meta' => [
                'title' => 'Workspace',
                'subtitle' => 'Centro de trabajo inteligente para operar, analizar y hacer crecer Famedic.',
                'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                'user_name' => $request->user()->name,
                'greeting' => $greeting,
            ],
        ]);
    }
}
