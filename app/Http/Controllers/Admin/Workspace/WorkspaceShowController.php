<?php

namespace App\Http\Controllers\Admin\Workspace;

use App\Http\Controllers\Controller;
use App\Services\Workspace\CustomerEngagementService;
use App\Services\Workspace\WorkspaceService;
use App\Support\Workspace\WorkspaceCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceShowController extends Controller
{
    public function __construct(
        private WorkspaceService $workspace,
        private CustomerEngagementService $customerEngagement,
    ) {
    }

    public function __invoke(Request $request, string $workspace): Response
    {
        abort_unless(in_array($workspace, WorkspaceCatalog::slugs(), true), 404);

        $administrator = $request->user()->administrator;
        abort_unless($administrator, 404);

        $payload = $this->workspace->workspaceFor($administrator, $workspace);
        abort_unless($payload, 403);

        if ($workspace === 'customer-engagement') {
            $engagement = $this->customerEngagement->build($request);

            return Inertia::render('Admin/Workspace/CustomerEngagement', [
                'workspace' => $payload,
                'engagement' => $engagement,
                'meta' => [
                    'generated_at' => $engagement['meta']['generated_at'] ?? now('America/Monterrey')->format('d/m/Y H:i'),
                    'user_name' => $request->user()->name,
                ],
            ]);
        }

        return Inertia::render('Admin/Workspace/Show', [
            'workspace' => $payload,
            'meta' => [
                'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                'user_name' => $request->user()->name,
            ],
        ]);
    }
}
