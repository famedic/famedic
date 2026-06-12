<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\Api\V1\LaboratoryOrderStatus;
use App\Support\Api\V1\OrderDocumentDownloadSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrderDocumentDownloadController extends Controller
{
    public function downloadResult(
        Request $request,
        int $orderId,
        OrderDocumentDownloadSupport $downloadSupport,
    ): Response|JsonResponse {
        $order = $downloadSupport->findCustomerOrder($request->user()->customer, $orderId);

        if ($order === null) {
            return ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
        }

        if (! LaboratoryOrderStatus::hasResults($order)) {
            return ApiResponse::error(
                'RESULT_NOT_READY',
                'El resultado aún no está disponible.',
                409,
            );
        }

        $resolved = $downloadSupport->resolveResultPdf($order);

        if (isset($resolved['error'])) {
            return ApiResponse::error(
                $resolved['error'],
                'El resultado aún no está disponible.',
                409,
            );
        }

        return $downloadSupport->pdfResponse($resolved['content'], $resolved['filename']);
    }

    public function downloadInvoice(
        Request $request,
        int $orderId,
        int $invoiceId,
        OrderDocumentDownloadSupport $downloadSupport,
    ): Response|JsonResponse {
        $order = $downloadSupport->findCustomerOrder($request->user()->customer, $orderId);

        if ($order === null) {
            return ApiResponse::error(
                'ORDER_NOT_FOUND',
                'Pedido no encontrado.',
                404,
            );
        }

        $resolved = $downloadSupport->resolveInvoicePdf($order, $invoiceId);

        if (isset($resolved['error'])) {
            return match ($resolved['error']) {
                'INVOICE_NOT_FOUND' => ApiResponse::error(
                    'INVOICE_NOT_FOUND',
                    'Factura no encontrada.',
                    404,
                ),
                default => ApiResponse::error(
                    'INVOICE_NOT_READY',
                    'La factura aún no está disponible.',
                    409,
                ),
            };
        }

        return $downloadSupport->pdfResponse($resolved['content'], $resolved['filename']);
    }
}
