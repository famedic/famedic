<?php

namespace App\Http\Controllers\Admin\Workspace;

use App\Http\Controllers\Controller;
use App\Services\Workspace\ActiveCampaignHubService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActiveCampaignHubController extends Controller
{
    public function __construct(
        private ActiveCampaignHubService $hub,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $payload = $this->hub->build($request);

        return Inertia::render('Admin/Workspace/ActiveCampaignHub', [
            'hub' => $payload,
            'meta' => [
                'title' => '💬 ActiveCampaign Hub',
                'subtitle' => 'Centro de integración entre Famedic y ActiveCampaign.',
                'generated_at' => $payload['meta']['generated_at'] ?? now('America/Monterrey')->format('d/m/Y H:i'),
                'user_name' => $request->user()->name,
            ],
        ]);
    }
}
