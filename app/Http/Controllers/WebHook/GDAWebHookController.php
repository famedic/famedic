<?php
namespace App\Http\Controllers\WebHook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class GDAWebHookController extends Controller
{
    public function saveNotification(Request $request)
    {
        // ✅ MÉTODO 1: Log detallado
        Log::info('🎯 [GDA WEBHOOK] saveNotification INICIADO', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'content_type' => $request->header('Content-Type'),
            'all_headers' => $request->headers->all(),
            'data' => $request->all()
        ]);

        // ✅ MÉTODO 2: Archivo de log físico (más confiable)
        $logMessage = "=== GDA WEBHOOK HIT ===\n" .
                     "Time: " . now()->toDateTimeString() . "\n" .
                     "URL: " . $request->fullUrl() . "\n" .
                     "Method: " . $request->method() . "\n" .
                     "IP: " . $request->ip() . "\n" .
                     "Data: " . json_encode($request->all()) . "\n" .
                     "=====================\n\n";
        
        File::append(storage_path('logs/gda_webhook_debug.log'), $logMessage);

        // ✅ MÉTODO 3: Response con información de debug
        return response()->json([
            'status' => 'success', // Cambié a success para ver mejor
            'message' => '✅ ¡Webhook funcionando correctamente!',
            'debug_info' => [
                'controller' => 'GDAWebHookController',
                'method' => 'saveNotification',
                'route' => 'gda.webhook.notification',
                'timestamp' => now()->toISOString(),
                'request_id' => uniqid()
            ],
            'received_data' => $request->all(),
            'server_info' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'timezone' => config('app.timezone')
            ]
        ], 200); // Cambié a 200 para ver mejor
    }

    public function handleResults(Request $request)
    {
        Log::info('📄 [GDA WEBHOOK] handleResults llamado', $request->all());
        
        return response()->json([
            'status' => 'success',
            'message' => 'Results webhook recibido',
            'data' => $request->all()
        ], 200);
    }
}