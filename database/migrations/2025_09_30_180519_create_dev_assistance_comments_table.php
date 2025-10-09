<?php

use App\Models\Administrator;
use App\Models\DevAssistanceRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dev_assistance_comments', function (Blueprint $table) {
            $table->id();
            $table->mediumText('comment');
            $table->foreignIdFor(DevAssistanceRequest::class)->constrained();
            $table->foreignIdFor(Administrator::class)->nullable()->constrained();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_assistance_comments');
    }
};
