<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * RC2 B02: bitácora append-only de aprobaciones comerciales de paquetes.
 * Cada fila registra quién aprobó, con qué referencia, cuándo, quién ejecutó
 * y el estado antes/después de toda la composición (estudios + descriptores).
 * Sin FKs: el historial debe sobrevivir a borrados del catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_package_commercial_approvals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('package_test_id');
            $table->string('approver', 160);
            $table->string('approval_reference', 120);
            $table->timestamp('approved_at');
            $table->string('operator', 160);
            $table->string('correlation_id', 128);
            $table->char('input_sha256', 64);
            $table->json('before_state');
            $table->json('after_state');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['package_test_id', 'created_at'], 'lt_pkg_comm_appr_pkg_created_idx');
            $table->index('approval_reference', 'lt_pkg_comm_appr_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_package_commercial_approvals');
    }
};
