<?php
namespace App\Http\Controllers\WebHook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GDANotificationController extends Controller
{
    public function saveNotification(Request $request)
    {
        // ✅ LOG para ver en storage/logs/laravel.log
        Log::info('✅ [GDA WEBHOOK] handleNotification LLAMADO', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'data' => $request->all()
        ]);

        // ✅ RETURN JSON explícito
        return response()->json([
            'status' => 'error',
            'message' => '🎉 ¡Webhook funcionando correctamente!',
            'received_data' => $request->all(),
            'timestamp' => now()->toISOString(),
            'debug' => 'Controller: WebHook\\GDANotificationController'
        ], 500);
    }

    public function testNotification(Request $request)
    {
        Log::info('🧪 [GDA TEST] Test notification', $request->all());
        
        return response()->json([
            'status' => 'test_success',
            'message' => 'Test endpoint working!',
            'data' => $request->all()
        ]);
    }

    public function handleResults(Request $request)
    {
        return response()->json([
            'status' => 'success', 
            'message' => 'Results webhook placeholder'
        ]);
    }

    public function checkResults(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Check results endpoint'
        ]);
    }

    public function testResults(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Test results working'
        ]);
    }
}