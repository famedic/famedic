<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\LaboratoryNotification;
use App\Models\User;
use App\Notifications\LaboratoryResultsAvailable;
use Illuminate\Support\Facades\Log;

class ResendLaboratoryNotifications extends Command
{
    protected $signature = 'laboratory:resend-notifications 
                            {--failed : Only resend failed notifications}
                            {--id= : Specific notification ID}
                            {--dry-run : Show what would be sent without actually sending}';
    
    protected $description = 'Resend laboratory result notifications';

    public function handle()
    {
        $query = LaboratoryNotification::where('notification_type', 'results')
            ->where('gda_status', 'completed');
        
        if ($this->option('id')) {
            $query->where('id', $this->option('id'));
        }
        
        if ($this->option('failed')) {
            $query->whereNotNull('email_error')
                  ->orWhereNull('email_sent_at');
        }
        
        $notifications = $query->get();
        
        $this->info("Found {$notifications->count()} notifications to process");
        
        $sent = 0;
        $failed = 0;
        
        foreach ($notifications as $notification) {
            $this->line("Processing notification #{$notification->id}...");
            
            // Intentar encontrar usuario
            $user = null;
            if ($notification->email_recipient_id) {
                $user = User::find($notification->email_recipient_id);
            }
            
            if (!$user && $notification->user_id) {
                $user = User::find($notification->user_id);
            }
            
            if (!$user) {
                $this->error("No user found for notification #{$notification->id}");
                $failed++;
                continue;
            }
            
            if ($this->option('dry-run')) {
                $this->info("[DRY RUN] Would send to: {$user->email}");
                $sent++;
                continue;
            }
            
            try {
                // Reconstruir datos para la notificación
                $payload = $notification->payload;
                $hasPdf = !empty($notification->results_pdf_base64);
                
                // Enviar notificación
                $user->notify(new LaboratoryResultsAvailable(
                    $notification->laboratory_purchase_id 
                        ? \App\Models\LaboratoryPurchase::find($notification->laboratory_purchase_id) 
                        : null,
                    $notification->laboratory_quote_id 
                        ? \App\Models\LaboratoryQuote::find($notification->laboratory_quote_id) 
                        : null,
                    $notification->gda_order_id,
                    $hasPdf
                ));
                
                // Actualizar registro
                $notification->update([
                    'email_sent_at' => now(),
                    'email_error' => null,
                    'email_recipient_id' => $user->id,
                    'email_recipient_email' => $user->email,
                ]);
                
                $this->info("✅ Sent to: {$user->email}");
                $sent++;
                
            } catch (\Exception $e) {
                $this->error("Failed to send to {$user->email}: {$e->getMessage()}");
                $notification->update(['email_error' => $e->getMessage()]);
                $failed++;
            }
        }
        
        $this->newLine();
        $this->info("Summary:");
        $this->info("  Sent successfully: {$sent}");
        $this->info("  Failed: {$failed}");
        
        return 0;
    }
}