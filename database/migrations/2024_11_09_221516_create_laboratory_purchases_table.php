<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_purchases', function (Blueprint $table) {
            $table->id();
            $table->enum('brand', array_map(fn($case) => $case->value, LaboratoryBrand::cases()));
            $table->string('gda_order_id');
            $table->string('name');
            $table->string('paternal_lastname');
            $table->string('maternal_lastname');
            $table->string('phone');
            $table->string('phone_country');
            $table->date('birth_date');
            $table->enum('gender', array_map(fn($case) => $case->value, Gender::cases()))->nullable();
            $table->string('street');
            $table->string('number');
            $table->string('neighborhood');
            $table->string('state');
            $table->string('city');
            $table->string('zipcode');
            $table->string('additional_references')->nullable();
            $table->unsignedInteger('total_cents');
            $table->string('results')->nullable();
            $table->foreignIdFor(Customer::class)->constrained();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_purchases');
    }
};
