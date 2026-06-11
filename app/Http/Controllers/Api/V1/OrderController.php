<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsFeatureDisabled;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Orders\CancelOrderRequest;
use App\Http\Requests\Api\V1\Orders\ListOrderInvoicesRequest;
use App\Http\Requests\Api\V1\Orders\ListOrderResultsRequest;
use App\Http\Requests\Api\V1\Orders\ListOrdersRequest;
use App\Http\Resources\Api\V1\OrderInvoiceResource;
use App\Http\Resources\Api\V1\OrderProductResource;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\OrderResultsListResource;
use App\Http\Resources\Api\V1\OrderStatusResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Support\Api\V1\LaboratoryOrderResults;
use App\Support\Api\V1\LaboratoryOrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use RespondsFeatureDisabled;

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $customer = $request->user()->customer;
        $validated = $request->validated();

        $filters = array_merge(
            ['deleted' => 'false'],
            array_filter([
                'search' => $validated['search'] ?? null,
                'brand' => $validated['brand'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        );

        $paginator = $customer->laboratoryPurchases()
            ->filter($filters)
            ->wherePatientStudyStatus($validated['status'] ?? null)
            ->withNotificationStatus()
            ->with(['laboratoryPurchaseItems'])
            ->latest()
            ->paginate(
                perPage: $validated['per_page'] ?? 20,
                page: $validated['page'] ?? null,
            );

        return ApiResponse::success([
            'orders' => OrderResource::collection($paginator->items())->resolve($request),
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function resultsIndex(ListOrderResultsRequest $request): JsonResponse
    {
        $customer = $request->user()->customer;
        $validated = $request->validated();

        $filters = array_merge(
            ['deleted' => 'false'],
            array_filter([
                'brand' => $validated['brand'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        );

        $paginator = $customer->laboratoryPurchases()
            ->filter($filters)
            ->wherePatientStudyStatus($validated['status'] ?? 'results_ready')
            ->with(['laboratoryPurchaseItems'])
            ->latest()
            ->paginate(
                perPage: $validated['per_page'] ?? 20,
                page: $validated['page'] ?? null,
            );

        return ApiResponse::success([
            'results' => OrderResultsListResource::collection($paginator->items())->resolve($request),
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function invoicesIndex(ListOrderInvoicesRequest $request): JsonResponse
    {
        $customer = $request->user()->customer;
        $validated = $request->validated();

        $filters = array_filter([
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $query = $customer->laboratoryPurchases()
            ->whereNull('deleted_at')
            ->filter($filters)
            ->with(['invoice', 'invoiceRequest', 'laboratoryPurchaseItems']);

        $status = $validated['status'] ?? null;

        if ($status === 'issued') {
            $query->whereHas('invoice');
        } elseif ($status === 'pending') {
            $query->whereHas('invoiceRequest')->whereDoesntHave('invoice');
        } else {
            $query->where(function ($builder) {
                $builder->whereHas('invoice')
                    ->orWhereHas('invoiceRequest');
            });
        }

        $paginator = $query->latest()->paginate(
            perPage: $validated['per_page'] ?? 20,
            page: $validated['page'] ?? null,
        );

        $invoices = collect($paginator->items())
            ->map(fn (LaboratoryPurchase $order) => $this->resolveInvoiceResource($order, $request))
            ->filter()
            ->values()
            ->all();

        return ApiResponse::success([
            'invoices' => $invoices,
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function products(Request $request, int $orderId): JsonResponse
    {
        $order = $this->findCustomerOrder($request->user()->customer, $orderId);

        if ($order === null) {
            return ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
        }

        $order->load(['laboratoryPurchaseItems']);

        $testsByGdaId = LaboratoryTest::query()
            ->where('brand', $order->brand)
            ->whereIn('gda_id', $order->laboratoryPurchaseItems->pluck('gda_id')->filter()->all())
            ->get()
            ->keyBy(fn (LaboratoryTest $test) => (string) $test->gda_id);

        $products = $order->laboratoryPurchaseItems
            ->map(fn ($item) => (new OrderProductResource(
                $item,
                $order,
                $testsByGdaId->get((string) $item->gda_id),
            ))->resolve($request))
            ->values()
            ->all();

        return ApiResponse::success([
            'order_id' => $order->id,
            'products' => $products,
        ]);
    }

    public function invoices(Request $request, int $orderId): JsonResponse
    {
        $order = $this->findCustomerOrder($request->user()->customer, $orderId);

        if ($order === null) {
            return ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
        }

        $order->load(['invoice', 'invoiceRequest', 'laboratoryPurchaseItems']);

        $invoice = $this->resolveInvoiceResource($order, $request, includeOrderStudyName: false);

        return ApiResponse::success([
            'order_id' => $order->id,
            'invoices' => $invoice !== null ? [$invoice] : [],
        ]);
    }

    public function results(Request $request, int $orderId): JsonResponse
    {
        $order = $this->findCustomerOrder($request->user()->customer, $orderId);

        if ($order === null) {
            return ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
        }

        if (! LaboratoryOrderStatus::hasResults($order)) {
            return ApiResponse::error(
                'RESULTS_NOT_AVAILABLE',
                'Los resultados no están disponibles para este pedido.',
                404,
            );
        }

        $order->load(['laboratoryPurchaseItems']);

        return ApiResponse::success(
            LaboratoryOrderResults::buildDetailPayload($order),
        );
    }

    public function status(Request $request, int $orderId): JsonResponse
    {
        $order = $this->findCustomerOrder($request->user()->customer, $orderId);

        if ($order === null) {
            return ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
        }

        return ApiResponse::success(
            (new OrderStatusResource($order))->resolve($request),
        );
    }

    public function cancel(CancelOrderRequest $request, int $orderId): JsonResponse
    {
        return $this->featureDisabled('La cancelación de pedidos no está disponible actualmente.');
    }

    private function findCustomerOrder(Customer $customer, int $orderId): ?LaboratoryPurchase
    {
        return $customer->laboratoryPurchases()
            ->withTrashed()
            ->find($orderId);
    }

    private function resolveInvoiceResource(
        LaboratoryPurchase $order,
        Request $request,
        bool $includeOrderStudyName = true,
    ): ?array {
        if ($order->invoice) {
            return (new OrderInvoiceResource(
                $order,
                $order,
                $order->invoice,
                includeOrderStudyName: $includeOrderStudyName,
            ))->resolve($request);
        }

        if ($order->invoiceRequest) {
            return (new OrderInvoiceResource(
                $order,
                $order,
                invoiceRequest: $order->invoiceRequest,
                includeOrderStudyName: $includeOrderStudyName,
            ))->resolve($request);
        }

        return null;
    }

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
