<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BenavidesCode;
use App\Models\BenavidesCodeImport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class BenavidesBenefitController extends Controller
{
    private const PERMISSION = 'benavides-benefit.manage';

    public function __invoke(Request $request): Response
    {
        $this->authorizeAdmin($request);

        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());

        $query = BenavidesCode::query()
            ->with([
                'user:id,name,paternal_lastname,maternal_lastname,email',
                'importBatch:id,original_filename,created_at',
            ])
            ->latest('id');

        if ($status === 'available') {
            $query->whereNull('user_id');
        } elseif ($status === 'assigned') {
            $query->whereNotNull('user_id');
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('email', 'like', "%{$search}%")
                            ->orWhere('id', $search)
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('paternal_lastname', 'like', "%{$search}%")
                            ->orWhere('maternal_lastname', 'like', "%{$search}%");
                    });
            });
        }

        $total = BenavidesCode::query()->count();
        $assigned = BenavidesCode::query()->whereNotNull('user_id')->count();
        $available = max(0, $total - $assigned);
        $latestImport = BenavidesCodeImport::query()
            ->with('confirmedByUser:id,name,paternal_lastname,maternal_lastname,email')
            ->latest('created_at')
            ->first();

        return Inertia::render('Admin/BenavidesBenefit/Index', [
            'metrics' => [
                'total' => $total,
                'available' => $available,
                'assigned' => $assigned,
                'usagePercent' => $total > 0 ? round(($assigned / $total) * 100, 2) : 0,
                'latestImport' => $latestImport ? [
                    'created_at' => $latestImport->created_at?->toIso8601String(),
                    'original_filename' => $latestImport->original_filename,
                    'imported_rows' => $latestImport->imported_rows,
                    'rejected_rows' => $latestImport->rejected_rows,
                    'confirmed_by' => $latestImport->confirmedByUser?->full_name ?: $latestImport->confirmedByUser?->email,
                ] : null,
            ],
            'codes' => $query->paginate(25)->withQueryString()->through(fn (BenavidesCode $code) => [
                'id' => $code->id,
                'code' => $code->code,
                'status' => $code->user_id ? 'assigned' : 'available',
                'assigned_at' => $code->assigned_at?->toIso8601String(),
                'created_at' => $code->created_at?->toIso8601String(),
                'user' => $code->user ? [
                    'id' => $code->user->id,
                    'name' => $code->user->full_name,
                    'email' => $code->user->email,
                ] : null,
                'import_batch' => $code->importBatch ? [
                    'id' => $code->importBatch->id,
                    'filename' => $code->importBatch->original_filename,
                    'created_at' => $code->importBatch->created_at?->toIso8601String(),
                ] : null,
            ]),
            'filters' => [
                'search' => $search,
                'status' => in_array($status, ['available', 'assigned'], true) ? $status : 'all',
            ],
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        try {
            abort_unless($request->user()?->administrator?->hasPermissionTo(self::PERMISSION), 403);
        } catch (PermissionDoesNotExist) {
            abort(403);
        }
    }
}
