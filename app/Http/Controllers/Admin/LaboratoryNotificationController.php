<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryNotification;
use App\Models\User;
use App\Notifications\LaboratoryResultsAvailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LaboratoryNotificationController extends Controller
{
    /**
     * Display a listing of the notifications.
     */
    public function index(Request $request)
    {
        $query = LaboratoryNotification::with(['user', 'laboratoryPurchase', 'laboratoryQuote'])
            ->orderBy('created_at', 'desc');
        
        // Filtros
        if ($request->filled('type')) {
            $query->where('notification_type', $request->type);
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('gda_order_id')) {
            $query->where('gda_order_id', 'like', '%' . $request->gda_order_id . '%');
        }
        
        if ($request->filled('has_email_error')) {
            $query->whereNotNull('email_error');
        }
        
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        $notifications = $query->paginate(25);
        
        return view('admin.laboratory-notifications.index', compact('notifications'));
    }
    
    /**
     * Display the specified notification.
     */
    public function show(LaboratoryNotification $notification)
    {
        $notification->load(['user', 'laboratoryPurchase.customer.user', 'laboratoryQuote.user']);
        
        return view('admin.laboratory-notifications.show', compact('notification'));
    }
    
    /**
     * Show detailed payload view.
     */
    public function showDetails(LaboratoryNotification $notification)
    {
        return view('admin.laboratory-notifications.details', compact('notification'));
    }
    
    /**
     * Resend a notification email.
     */
    public function resend(LaboratoryNotification $notification)
    {
        try {
            // Intentar encontrar usuario
            $user = null;
            
            if ($notification->email_recipient_id) {
                $user = User::find($notification->email_recipient_id);
            }
            
            if (!$user && $notification->user_id) {
                $user = User::find($notification->user_id);
            }
            
            // Si aún no encontramos usuario, intentar por purchase o quote
            if (!$user && $notification->laboratory_purchase_id) {
                $purchase = $notification->laboratoryPurchase()->with('customer.user')->first();
                if ($purchase && $purchase->customer && $purchase->customer->user) {
                    $user = $purchase->customer->user;
                }
            }
            
            if (!$user && $notification->laboratory_quote_id) {
                $quote = $notification->laboratoryQuote()->with('user')->first();
                if ($quote && $quote->user) {
                    $user = $quote->user;
                }
            }
            
            if (!$user) {
                return back()->with('error', 'No se pudo encontrar el usuario asociado a esta notificación.');
            }
            
            // Verificar que tenga email
            if (empty($user->email)) {
                return back()->with('error', 'El usuario no tiene dirección de email registrada.');
            }
            
            // Reenviar notificación
            $user->notify(new LaboratoryResultsAvailable(
                $notification->laboratoryPurchase,
                $notification->laboratoryQuote,
                $notification->gda_order_id,
                !empty($notification->results_pdf_base64)
            ));
            
            // Actualizar registro
            $notification->update([
                'email_sent_at' => now(),
                'email_error' => null,
                'email_recipient_id' => $user->id,
                'email_recipient_email' => $user->email,
                'resend_count' => ($notification->resend_count ?? 0) + 1,
            ]);
            
            Log::info('Admin re-sent laboratory notification', [
                'notification_id' => $notification->id,
                'admin_id' => auth()->id(),
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            
            return back()->with('success', 'Notificación reenviada exitosamente a ' . $user->email);
            
        } catch (\Exception $e) {
            Log::error('Failed to resend laboratory notification', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $notification->update(['email_error' => $e->getMessage()]);
            
            return back()->with('error', 'Error al reenviar: ' . $e->getMessage());
        }
    }
    
    /**
     * Clean error from notification.
     */
    public function cleanError(LaboratoryNotification $notification)
    {
        try {
            $notification->update([
                'email_error' => null,
                'email_attempted_at' => null,
            ]);
            
            return back()->with('success', 'Error limpiado exitosamente.');
            
        } catch (\Exception $e) {
            return back()->with('error', 'Error al limpiar: ' . $e->getMessage());
        }
    }
    
    /**
     * Get statistics for dashboard.
     */
    public function statistics()
    {
        $today = now()->startOfDay();
        $lastWeek = now()->subWeek();
        
        $stats = [
            'total' => LaboratoryNotification::count(),
            'today' => LaboratoryNotification::whereDate('created_at', today())->count(),
            'last_week' => LaboratoryNotification::where('created_at', '>=', $lastWeek)->count(),
            'with_results' => LaboratoryNotification::whereNotNull('results_pdf_base64')->count(),
            'failed_emails' => LaboratoryNotification::whereNotNull('email_error')->count(),
            'sent_emails' => LaboratoryNotification::whereNotNull('email_sent_at')->count(),
            'by_type' => LaboratoryNotification::selectRaw('notification_type, count(*) as count')
                ->groupBy('notification_type')
                ->get()
                ->pluck('count', 'notification_type'),
        ];
        
        return response()->json($stats);
    }
}