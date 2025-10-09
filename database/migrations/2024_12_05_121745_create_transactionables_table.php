<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactionables', function (Blueprint $table) {
            $table->foreignIdFor(Transaction::class)->constrained();
            $table->morphs('transactionable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactionables');
    }
};
