<?php

namespace App\Http\Controllers;

use App\Models\LaboratoryNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaboratoryEndpointController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $items = LaboratoryNotification::latest()->get();

        return response()->json([
            'success' => true,
            'message' => 'Laboratory notifications retrieved successfully',
            'data' => $items
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notification_type' => 'required|string|in:notification,results,status_update',
            'gda_order_id' => 'nullable|string',
            'gda_external_id' => 'nullable|string',
            'gda_acuse' => 'nullable|string',
            'gda_status' => 'nullable|string|in:completed,in-progress,cancelled,active',
            'resource_type' => 'nullable|string|in:ServiceRequest,ServiceRequestCotizacion',
            'payload' => 'nullable|array',
            'gda_message' => 'nullable|array',
            'laboratory_quote_id' => 'nullable|integer|exists:laboratory_quotes,id',
            'laboratory_purchase_id' => 'nullable|integer|exists:laboratory_purchases,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'contact_id' => 'nullable|integer|exists:contacts,id',
            'context' => 'nullable|string|max:255',
        ]);

        // Establecer valores por defecto
        $validated['status'] = LaboratoryNotification::STATUS_RECEIVED;

        $item = LaboratoryNotification::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Laboratory notification created successfully',
            'data' => $item->load(['user', 'contact']) // Cargar relaciones
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $item = LaboratoryNotification::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Laboratory notification not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Laboratory notification retrieved successfully',
            'data' => $item
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $item = LaboratoryNotification::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Laboratory notification not found'
            ], 404);
        }

        $validated = $request->validate([
            'notification_type' => 'sometimes|string|in:notification,results,status_update',
            'gda_order_id' => 'nullable|string',
            'gda_external_id' => 'nullable|string',
            'gda_acuse' => 'nullable|string',
            'gda_status' => 'nullable|string|in:completed,in-progress,cancelled,active',
            'status' => 'sometimes|string|in:received,processed,error',
            'resource_type' => 'nullable|string|in:ServiceRequest,ServiceRequestCotizacion',
            'payload' => 'nullable|array',
            'gda_message' => 'nullable|array',
            'results_pdf_base64' => 'nullable|string',
            'results_received_at' => 'nullable|date',
        ]);

        $item->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Laboratory notification updated successfully',
            'data' => $item
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $item = LaboratoryNotification::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Laboratory notification not found'
            ], 404);
        }

        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Laboratory notification deleted successfully'
        ]);
    }

    /**
     * Simple test endpoint
     */
    public function test(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Laboratory API is working!',
            'timestamp' => now(),
            'data' => [
                'status' => 'active',
                'version' => '1.0',
                'notification_types' => LaboratoryNotification::getNotificationTypes(),
                'statuses' => LaboratoryNotification::getStatuses(),
                'gda_statuses' => LaboratoryNotification::getGdaStatuses()
            ]
        ]);
    }

    /**
     * Create a test notification
     */
    public function createTest(): JsonResponse
    {
        $testData = [
            'notification_type' => 'notification',
            'gda_order_id' => 'TEST-' . now()->timestamp,
            'gda_external_id' => 'EXT-' . now()->timestamp,
            'gda_acuse' => 'ACUSE-TEST-' . uniqid(),
            'gda_status' => 'active',
            'resource_type' => 'ServiceRequest',
            'payload' => [
                'test' => true,
                'message' => 'This is a test notification',
                'created_at' => now()->toISOString()
            ],
            'gda_message' => [
                'type' => 'test',
                'content' => 'Test message content'
            ],
            'status' => 'received'
        ];

        $item = LaboratoryNotification::create($testData);

        return response()->json([
            'success' => true,
            'message' => 'Test laboratory notification created successfully',
            'data' => $item
        ], 201);
    }
}