<?php

use App\Enums\InvoiceRequestWorkflowStatus;
use App\Models\LaboratoryPurchase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_requests', function (Blueprint $table) {
            $table->string('workflow_status', 50)
                ->default(InvoiceRequestWorkflowStatus::AwaitingSampleCollection->value)
                ->after('fiscal_certificate');
            $table->timestamp('submitted_to_billing_at')->nullable()->after('workflow_status');
            $table->timestamp('sample_completed_at')->nullable()->after('submitted_to_billing_at');
            $table->timestamp('billing_team_notified_at')->nullable()->after('sample_completed_at');
            $table->string('activated_by', 50)->nullable()->after('billing_team_notified_at');
        });

        /*
         * Backfill seguro para solicitudes históricas:
         *
         * - Todas entraron al flujo de facturación inmediato (controller envía correo al crear).
         * - submitted_to_billing_at = created_at refleja cuándo la solicitud quedó disponible para facturación.
         * - billing_team_notified_at = created_at solo para compras de laboratorio, porque
         *   InvoiceRequestController (lab) notifica al equipo en el mismo request POST.
         * - Farmacia no notifica al equipo de facturación al crear; billing_team_notified_at queda null.
         */
        DB::table('invoice_requests')->update([
            'workflow_status' => InvoiceRequestWorkflowStatus::SubmittedToBilling->value,
            'submitted_to_billing_at' => DB::raw('created_at'),
        ]);

        DB::table('invoice_requests')
            ->where('invoice_requestable_type', LaboratoryPurchase::class)
            ->update([
                'billing_team_notified_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('invoice_requests', function (Blueprint $table) {
            $table->dropColumn([
                'workflow_status',
                'submitted_to_billing_at',
                'sample_completed_at',
                'billing_team_notified_at',
                'activated_by',
            ]);
        });
    }
};
