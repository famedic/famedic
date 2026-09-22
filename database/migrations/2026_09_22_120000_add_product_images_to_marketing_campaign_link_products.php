<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_link_products', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_campaign_link_products', 'image_source')) {
                $table->string('image_source', 20)->default('none')->after('is_featured');
            }
            if (! Schema::hasColumn('marketing_campaign_link_products', 'image_disk')) {
                $table->string('image_disk', 40)->nullable()->after('image_source');
            }
            if (! Schema::hasColumn('marketing_campaign_link_products', 'image_path')) {
                $table->string('image_path', 500)->nullable()->after('image_disk');
            }
            if (! Schema::hasColumn('marketing_campaign_link_products', 'image_alt')) {
                $table->string('image_alt', 180)->nullable()->after('image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaign_link_products', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['image_source', 'image_disk', 'image_path', 'image_alt'],
                fn (string $column) => Schema::hasColumn('marketing_campaign_link_products', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
