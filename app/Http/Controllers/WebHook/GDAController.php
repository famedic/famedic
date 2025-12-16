<?php
namespace App\Http\Controllers\WebHook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class GDAController extends Controller
{
    public function saveNotification(Request $request)
    {
        // Crear archivo físico inmediatamente
        \Illuminate\Support\Facades\File::put(
            storage_path('logs/gda_simple_test.txt'),
            "🎯 GDA SIMPLE TEST - CONTROLLER HIT!\n" .
            "Time: " . now()->toDateTimeString() . "\n" .
            "Data: " . json_encode($request->all()) . "\n\n"
        );

        return response()->json([
            'status' => 'success',
            'message' => '✅ GDA Controller funcionando',
            'data_received' => $request->all(),
            'timestamp' => now()->toISOString()
        ]);
    }

    public function handleResults(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Results endpoint'
        ]);
    }
}