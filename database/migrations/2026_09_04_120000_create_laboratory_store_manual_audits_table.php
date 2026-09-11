<?php

use App\Models\Administrator;
use App\Models\LaboratoryStore;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_store_manual_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(LaboratoryStore::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Administrator::class)->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('scope', 64);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();

            $table->index(['laboratory_store_id', 'created_at'], 'lsma_store_created_idx');
            $table->index(['action', 'scope'], 'lsma_action_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_store_manual_audits');
    }
};
