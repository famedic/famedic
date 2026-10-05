<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benavides_code_imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename')->nullable();
            $table->string('file_hash', 64)->nullable()->index();
            $table->string('status', 32)->index();
            $table->foreignIdFor(User::class, 'uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignIdFor(User::class, 'confirmed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('empty_rows')->default(0);
            $table->unsignedInteger('duplicate_file_rows')->default(0);
            $table->unsignedInteger('duplicate_database_rows')->default(0);
            $table->unsignedInteger('assigned_conflict_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->json('summary_json')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benavides_code_imports');
    }
};
