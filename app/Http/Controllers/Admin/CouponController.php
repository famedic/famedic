<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\CouponsExcelImport;
use App\Models\Coupon;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class CouponController extends Controller
{
    public function __construct(
        private CouponService $couponService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Coupon::class);

        $coupons = Coupon::query()
            ->withCount('couponUsers')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Coupons/Index', [
            'coupons' => $coupons,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Coupon::class);

        return Inertia::render('Admin/Coupons/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        Coupon::create([
            'code' => $data['code'] ?? null,
            'amount_cents' => $data['amount_cents'],
            'remaining_cents' => $data['amount_cents'],
            'type' => 'balance',
            'is_active' => $data['is_active'] ?? true,
        ]);

        return redirect()->route('admin.coupons.index')->flashMessage('Cupón creado.');
    }

    public function edit(Coupon $coupon): Response
    {
        $this->authorize('update', $coupon);

        return Inertia::render('Admin/Coupons/Edit', [
            'coupon' => $coupon,
        ]);
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorize('update', $coupon);

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $coupon->code = $data['code'] ?? null;
        $coupon->is_active = $data['is_active'] ?? $coupon->is_active;
        $coupon->save();

        return redirect()->route('admin.coupons.index')->flashMessage('Cupón actualizado.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $this->authorize('delete', $coupon);

        $coupon->is_active = false;
        $coupon->save();

        return redirect()->route('admin.coupons.index')->flashMessage('Cupón desactivado.');
    }

    public function assignForm(): Response
    {
        $this->authorize('create', Coupon::class);

        return Inertia::render('Admin/Coupons/Assign');
    }

    public function assign(Request $request): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'send_notification' => ['boolean'],
            'code' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        $this->couponService->assignCouponToUser(
            $user,
            $data['amount_cents'],
            $data['send_notification'] ?? true,
            $data['code'] ?? null
        );

        return redirect()->route('admin.coupons.index')->flashMessage('Saldo asignado al usuario.');
    }

    public function importForm(): Response
    {
        $this->authorize('create', Coupon::class);

        return Inertia::render('Admin/Coupons/Import');
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorize('create', Coupon::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'send_notifications' => ['boolean'],
        ]);

        Excel::import(
            new CouponsExcelImport($this->couponService, $request->boolean('send_notifications')),
            $request->file('file')
        );

        return redirect()->route('admin.coupons.index')->flashMessage('Importación procesada.');
    }
}
