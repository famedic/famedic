<?php

namespace App\Http\Controllers;

use App\Models\TestApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestApiController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $items = TestApi::where('is_active', true)->get();
        
        return response()->json([
            'success' => true,
            'message' => 'Test items retrieved successfully',
            'data' => $items
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $item = TestApi::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Test item created successfully',
            'data' => $item
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $item = TestApi::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Test item not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Test item retrieved successfully',
            'data' => $item
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $item = TestApi::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Test item not found'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $item->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Test item updated successfully',
            'data' => $item
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $item = TestApi::find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Test item not found'
            ], 404);
        }

        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Test item deleted successfully'
        ]);
    }

    /**
     * Simple test endpoint
     */
    public function test(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'API is working!',
            'timestamp' => now(),
            'data' => [
                'status' => 'active',
                'version' => '1.0'
            ]
        ]);
    }
}