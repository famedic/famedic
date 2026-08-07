<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_links', function (Blueprint $table) {
            $table->string('public_title', 180)->nullable()->after('target_payload');
            $table->string('public_subtitle', 255)->nullable()->after('public_title');
            $table->text('public_description')->nullable()->after('public_subtitle');
            $table->string('eyebrow', 120)->nullable()->after('public_description');
            $table->string('hero_image_path', 500)->nullable()->after('eyebrow');
            $table->string('primary_cta_label', 80)->nullable()->after('hero_image_path');
            $table->string('secondary_cta_label', 80)->nullable()->after('primary_cta_label');
            $table->boolean('show_prices')->default(true)->after('secondary_cta_label');
            $table->boolean('show_brand_logo')->default(true)->after('show_prices');
            $table->boolean('show_campaign_dates')->default(false)->after('show_brand_logo');
            $table->string('landing_layout', 40)->default('default')->after('show_campaign_dates');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaign_links', function (Blueprint $table) {
            $table->dropColumn([
                'public_title',
                'public_subtitle',
                'public_description',
                'eyebrow',
                'hero_image_path',
                'primary_cta_label',
                'secondary_cta_label',
                'show_prices',
                'show_brand_logo',
                'show_campaign_dates',
                'landing_layout',
            ]);
        });
    }
};
