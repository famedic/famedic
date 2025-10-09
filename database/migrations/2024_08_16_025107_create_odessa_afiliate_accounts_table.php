<?php

use App\Models\OdessaAfiliatedCompany;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odessa_afiliate_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('odessa_identifier')->unique();
            $table->string('partner_identifier')->nullable();
            $table->foreignIdFor(OdessaAfiliatedCompany::class)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odessa_afiliate_accounts');
    }
};
