<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponConcept;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CouponConceptController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('configure', Coupon::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        CouponConcept::create([
            'title' => trim($data['title']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
        ]);

        return redirect()
            ->route('admin.coupons.settings', ['tab' => 'concepts'])
            ->flashMessage('Concepto creado.');
    }

    public function update(Request $request, CouponConcept $couponConcept): RedirectResponse
    {
        $this->authorize('configure', Coupon::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $couponConcept->update([
            'title' => trim($data['title']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
        ]);

        return redirect()
            ->route('admin.coupons.settings', ['tab' => 'concepts'])
            ->flashMessage('Concepto actualizado.');
    }

    public function destroy(CouponConcept $couponConcept): RedirectResponse
    {
        $this->authorize('configure', Coupon::class);

        $couponConcept->delete();

        return redirect()
            ->route('admin.coupons.settings', ['tab' => 'concepts'])
            ->flashMessage('Concepto eliminado.');
    }
}
