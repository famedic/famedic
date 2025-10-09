<?php

use App\Models\Administrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dev_assistance_requests', function (Blueprint $table) {
            $table->id();
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignIdFor(Administrator::class)->constrained();
            $table->morphs('dev_assistance_requestable', 'dev_assistance_requestable_index');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_assistance_requests');
    }
};
