<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CartController extends Controller
{
    public function index(Request $request)
    {
        $request->user()->administrator->hasPermissionTo('view carts') || abort(403);

        $filters = collect($request->only([
            'search',
            'type',
            'display_status',
            'start_date',
            'end_date',
        ]))->filter(fn ($v) => $v !== null && $v !== '')->all();

        $start = ! empty($filters['start_date'])
            ? Carbon::parse($filters['start_date'], 'America/Monterrey')->startOfDay()->utc()
            : null;
        $end = ! empty($filters['end_date'])
            ? Carbon::parse($filters['end_date'], 'America/Monterrey')->endOfDay()->utc()
            : null;

        $query = Cart::query()
            ->with(['user'])
            ->withCount('items')
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where(function ($inner) use ($search) {
                        $inner->where('name', 'like', '%' . $search . '%')
                            ->orWhere('paternal_lastname', 'like', '%' . $search . '%')
                            ->orWhere('maternal_lastname', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    });
                });
            })
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->when($filters['display_status'] ?? null, function ($q, string $status) {
                $q->displayStatusFilter($status);
            })
            ->when($start, fn ($q, $d) => $q->where('updated_at', '>=', $d))
            ->when($end, fn ($q, $d) => $q->where('updated_at', '<=', $d))
            ->orderByDesc('updated_at');

        $carts = $query->paginate(25)->withQueryString();

        $metricsBase = Cart::query()
            ->when($filters['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->when($start, fn ($q, $d) => $q->where('updated_at', '>=', $d))
            ->when($end, fn ($q, $d) => $q->where('updated_at', '<=', $d));

        $staleBefore = now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);

        $metrics = [
            'active' => (clone $metricsBase)
                ->where('status', MonitoringCartStatus::Active)
                ->where('updated_at', '>=', $staleBefore)
                ->count(),
            'abandoned' => (clone $metricsBase)
                ->where('status', MonitoringCartStatus::Active)
                ->where('updated_at', '<', $staleBefore)
                ->count(),
            'completed' => (clone $metricsBase)->where('status', MonitoringCartStatus::Completed)->count(),
        ];

        $den = $metrics['completed'] + $metrics['abandoned'];
        $metrics['conversion_percent'] = $den > 0
            ? round(100 * $metrics['completed'] / $den, 1)
            : null;

        $carts->getCollection()->transform(fn (Cart $cart) => $this->serializeCartRow($cart));

        return Inertia::render('Admin/Carts', [
            'carts' => $carts,
            'filters' => $filters,
            'metrics' => $metrics,
            'canViewCartDetails' => $request->user()->administrator->hasPermissionTo('view cart details'),
        ]);
    }

    public function show(Request $request, Cart $cart)
    {
        $request->user()->administrator->hasPermissionTo('view cart details') || abort(403);

        $cart->load(['user', 'items']);

        return Inertia::render('Admin/CartShow', [
            'cart' => $this->serializeCartDetail($cart),
        ]);
    }

    private function serializeCartRow(Cart $cart): array
    {
        $display = $cart->displayStatus();

        return [
            'id' => $cart->id,
            'user' => $cart->user ? [
                'id' => $cart->user->id,
                'full_name' => $cart->user->full_name,
                'email' => $cart->user->email,
            ] : null,
            'type' => $cart->type->value,
            'type_label' => $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
            'items_count' => $cart->items_count ?? $cart->items->count(),
            'total' => (string) $cart->total,
            'total_formatted' => formattedPrice((float) $cart->total),
            'display_status' => $display,
            'updated_at' => $cart->updated_at?->toIso8601String(),
            'updated_at_human' => $cart->updated_at?->format('d/m/Y H:i'),
        ];
    }

    private function serializeCartDetail(Cart $cart): array
    {
        $items = $cart->items->map(fn ($row) => [
            'id' => $row->id,
            'name' => $row->name,
            'quantity' => $row->quantity,
            'unit_price' => (string) $row->price,
            'unit_price_formatted' => formattedPrice((float) $row->price),
            'line_total' => (string) round((float) $row->price * (int) $row->quantity, 2),
            'line_total_formatted' => formattedPrice(round((float) $row->price * (int) $row->quantity, 2)),
        ]);

        return [
            'id' => $cart->id,
            'user' => $cart->user ? [
                'id' => $cart->user->id,
                'full_name' => $cart->user->full_name,
                'email' => $cart->user->email,
            ] : null,
            'type' => $cart->type->value,
            'type_label' => $cart->type === MonitoringCartType::Pharmacy ? 'Farmacia' : 'Laboratorio',
            'total' => (string) $cart->total,
            'total_formatted' => formattedPrice((float) $cart->total),
            'display_status' => $cart->displayStatus(),
            'items' => $items,
            'updated_at_human' => $cart->updated_at?->format('d/m/Y H:i'),
        ];
    }
}
