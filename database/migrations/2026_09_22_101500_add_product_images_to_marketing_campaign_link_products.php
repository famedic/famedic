<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_link_products', function (Blueprint $table) {
            $table->string('image_source', 20)->default('none')->after('is_featured');
            $table->string('image_disk', 40)->nullable()->after('image_source');
            $table->string('image_path', 500)->nullable()->after('image_disk');
            $table->string('image_alt', 180)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaign_link_products', function (Blueprint $table) {
            $table->dropColumn([
                'image_source',
                'image_disk',
                'image_path',
                'image_alt',
            ]);
        });
    }
};
