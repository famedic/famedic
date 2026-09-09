<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Otp\Monitoring\OtpMovementQueryService;
use App\Services\Otp\Monitoring\VonageSmsConnectionTestService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OtpMovementsMonitorController extends Controller
{
    public function __construct(
        private readonly OtpMovementQueryService $queryService,
        private readonly VonageSmsConnectionTestService $smsConnectionTestService,
    ) {}

    public function index(Request $request)
    {
        $request->user()->administrator->hasPermissionTo('otp-movements.monitor') || abort(403);

        $tz = config('app.timezone', 'UTC');

        $startDate = $request->get('start_date')
            ? Carbon::parse($request->get('start_date'), $tz)->startOfDay()->utc()
            : now($tz)->subDays(7)->startOfDay()->utc();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->get('end_date'), $tz)->endOfDay()->utc()
            : now($tz)->endOfDay()->utc();

        $filters = collect($request->only([
            'flow',
            'status',
            'stage',
            'channel',
            'provider',
            'endpoint',
            'correlation_id',
            'challenge_id',
            'user_id',
            'customer_id',
            'destination',
            'failed_only',
            'replay_only',
            'sms_delivery_status',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        $filters['start_date'] = $startDate->timezone($tz)->toDateString();
        $filters['end_date'] = $endDate->timezone($tz)->toDateString();

        $result = $this->queryService->paginate($filters, $startDate, $endDate);

        return Inertia::render('Admin/OtpMovements/Index', [
            'events' => $result['events'],
            'summary' => $result['summary'],
            'filters' => $filters,
            'options' => $result['options'],
            'smsDiagnostic' => array_merge(
                $this->smsConnectionTestService->metadata(),
                [
                    'can_send' => (bool) $request->user()->administrator?->hasPermissionTo('otp-movements.test-sms'),
                    'message' => config('vonage.sms_diagnostic.message'),
                ],
            ),
        ]);
    }

    public function show(Request $request, string $movementKey)
    {
        $request->user()->administrator->hasPermissionTo('otp-movements.monitor') || abort(403);

        $detail = $this->queryService->showMovement(urldecode($movementKey));

        return Inertia::render('Admin/OtpMovements/Show', $detail);
    }

    public function testSms(Request $request)
    {
        $administrator = $request->user()?->administrator;
        $administrator?->hasPermissionTo('otp-movements.test-sms') || abort(403);

        $validated = $request->validate([
            'destination' => ['required', 'string', 'max:32'],
            'mode' => ['required', 'string', 'in:send_only,send_with_dlr'],
            'confirm' => ['accepted'],
        ]);

        $result = $this->smsConnectionTestService->send(
            (int) $administrator->id,
            (string) $validated['destination'],
            (string) $validated['mode'],
        );

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }
}
