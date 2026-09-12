<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_links', function (Blueprint $table) {
            $table->string('hero_image_source', 20)->default('none')->after('hero_image_path');
            $table->string('hero_image_disk', 40)->nullable()->after('hero_image_source');
            $table->string('hero_image_url', 1000)->nullable()->after('hero_image_disk');
            $table->string('hero_image_alt', 180)->nullable()->after('hero_image_url');
        });

        // Compatibilidad: paths internos previos se marcan como upload/local.
        if (Schema::hasColumn('marketing_campaign_links', 'hero_image_path')) {
            DB::table('marketing_campaign_links')
                ->whereNotNull('hero_image_path')
                ->where('hero_image_path', '!=', '')
                ->update([
                    'hero_image_source' => 'upload',
                    'hero_image_disk' => 'public',
                ]);
        }

        Schema::create('marketing_campaign_link_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_link_id')
                ->constrained('marketing_campaign_links', 'id', 'mc_link_products_link_fk')
                ->cascadeOnDelete();
            $table->foreignId('laboratory_test_id')
                ->constrained('laboratory_tests', 'id', 'mc_link_products_test_fk')
                ->restrictOnDelete();
            $table->string('section', 30)->default('primary');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_featured')->default(true);
            $table->timestamps();

            $table->unique(
                ['marketing_campaign_link_id', 'laboratory_test_id', 'section'],
                'mc_link_products_unique'
            );
            $table->index(
                ['marketing_campaign_link_id', 'section', 'position'],
                'mc_link_products_section_pos_idx'
            );
        });

        Schema::create('marketing_campaign_link_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_link_id')
                ->constrained('marketing_campaign_links', 'id', 'mc_link_categories_link_fk')
                ->cascadeOnDelete();
            $table->foreignId('laboratory_test_category_id')
                ->constrained('laboratory_test_categories', 'id', 'mc_link_categories_cat_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(
                ['marketing_campaign_link_id', 'laboratory_test_category_id'],
                'mc_link_categories_unique'
            );
            $table->index(
                ['marketing_campaign_link_id', 'position'],
                'mc_link_categories_pos_idx'
            );
        });

        Schema::create('marketing_campaign_link_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_link_id')
                ->constrained('marketing_campaign_links', 'id', 'mc_link_images_link_fk')
                ->cascadeOnDelete();
            $table->string('type', 30)->default('gallery');
            $table->string('source', 20)->default('upload');
            $table->string('disk', 40)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('external_url', 1000)->nullable();
            $table->string('alt_text', 180)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(
                ['marketing_campaign_link_id', 'type', 'position'],
                'mc_link_images_type_pos_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_link_images');
        Schema::dropIfExists('marketing_campaign_link_categories');
        Schema::dropIfExists('marketing_campaign_link_products');

        Schema::table('marketing_campaign_links', function (Blueprint $table) {
            $table->dropColumn([
                'hero_image_source',
                'hero_image_disk',
                'hero_image_url',
                'hero_image_alt',
            ]);
        });
    }
};
