<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
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
        Schema::create('laboratory_appointments', function (Blueprint $table) {
            $table->id();
            $table->dateTime('appointment_date')->nullable();
            $table->enum('brand', array_map(fn($case) => $case->value, LaboratoryBrand::cases()));
            $table->string('patient_name')->nullable();
            $table->string('patient_paternal_lastname')->nullable();
            $table->string('patient_maternal_lastname')->nullable();
            $table->string('patient_phone')->nullable();
            $table->string('patient_phone_country')->nullable();
            $table->date('patient_birth_date')->nullable();
            $table->enum('patient_gender', array_map(fn($case) => $case->value, Gender::cases()))->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->mediumText('notes')->nullable();
            $table->foreignIdFor(Customer::class)->constrained();
            $table->foreignIdFor(LaboratoryStore::class)->nullable()->constrained();
            $table->foreignIdFor(LaboratoryPurchase::class)->nullable()->constrained();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laboratory_appointments');
    }
};
