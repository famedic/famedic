<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\V1\CreateAkubicaInvoiceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Orders\CreateInvoiceRequestRequest;
use App\Http\Resources\Api\V1\InvoiceRequestResource;
use App\Http\Responses\ApiResponse;
use App\Support\Api\V1\LaboratoryInvoiceSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderInvoiceRequestController extends Controller
{
    public function store(
        CreateInvoiceRequestRequest $request,
        int $orderId,
        LaboratoryInvoiceSupport $invoiceSupport,
        CreateAkubicaInvoiceRequestAction $createInvoiceRequestAction,
    ): JsonResponse {
        $customer = $request->user()->customer;

        $order = $invoiceSupport->findOwnedOrder($customer->id, $orderId);

        if (! $order) {
            return ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
        }

        $taxProfile = $invoiceSupport->findOwnedTaxProfile(
            $customer->id,
            (int) $request->validated('tax_profile_id'),
        );

        if (! $taxProfile) {
            return ApiResponse::error(
                'TAX_PROFILE_NOT_FOUND',
                'El perfil fiscal no fue encontrado.',
                404,
            );
        }

        $result = $createInvoiceRequestAction(
            $order,
            $taxProfile,
            $request->validated('cfdi_use'),
        );

        if (isset($result['error'])) {
            return match ($result['error']) {
                'INVOICE_ALREADY_EXISTS' => ApiResponse::error(
                    'INVOICE_ALREADY_EXISTS',
                    'Este pedido ya tiene una factura emitida.',
                    409,
                ),
                'INVOICE_REQUEST_ALREADY_EXISTS' => ApiResponse::error(
                    'INVOICE_REQUEST_ALREADY_EXISTS',
                    'Ya existe una solicitud de factura para este pedido.',
                    409,
                ),
                'ORDER_NOT_INVOICEABLE' => ApiResponse::error(
                    'ORDER_NOT_INVOICEABLE',
                    'Este pedido no puede solicitar factura.',
                    409,
                ),
                default => ApiResponse::error(
                    'INTERNAL_ERROR',
                    'Ocurrió un error inesperado.',
                    500,
                ),
            };
        }

        return ApiResponse::success([
            'invoice_request' => (new InvoiceRequestResource(
                $result['invoice_request'],
                $order->id,
                $result['tax_profile_id'],
            ))->resolve($request),
        ], status: 201);
    }

    public function status(
        Request $request,
        int $orderId,
        LaboratoryInvoiceSupport $invoiceSupport,
    ): JsonResponse {
        $order = $invoiceSupport->findOwnedOrder($request->user()->customer->id, $orderId);

        if (! $order) {
            return ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
        }

        return ApiResponse::success(
            $invoiceSupport->buildStatusPayload($order),
        );
    }
}
