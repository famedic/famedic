<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('laboratory_quote_items', function (Blueprint $table) {
            $table->id();
            $table->string('gda_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('feature_list')->nullable();
            $table->text('indications')->nullable();
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('laboratory_quote_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['laboratory_quote_id', 'gda_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_quote_items');
    }
};
