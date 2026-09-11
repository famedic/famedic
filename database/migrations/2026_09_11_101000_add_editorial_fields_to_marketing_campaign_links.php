<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_links', function (Blueprint $table) {
            $table->string('editorial_eyebrow', 120)->nullable()->after('landing_template');
            $table->string('editorial_title', 180)->nullable()->after('editorial_eyebrow');
            $table->text('editorial_body')->nullable()->after('editorial_title');
            $table->json('editorial_items')->nullable()->after('editorial_body');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaign_links', function (Blueprint $table) {
            $table->dropColumn([
                'editorial_eyebrow',
                'editorial_title',
                'editorial_body',
                'editorial_items',
            ]);
        });
    }
};
