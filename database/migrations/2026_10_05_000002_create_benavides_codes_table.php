<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benavides_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignIdFor(User::class)
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable()->index();
            $table->foreignId('import_batch_id')
                ->nullable()
                ->constrained('benavides_code_imports')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benavides_codes');
    }
};
