<?php

use App\Models\Administrator;
use App\Models\LaboratoryTest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignIdFor(Administrator::class, 'created_by')
                ->nullable()
                ->constrained('administrators', 'id', 'mc_campaigns_created_by_fk')
                ->nullOnDelete();
            $table->foreignIdFor(Administrator::class, 'updated_by')
                ->nullable()
                ->constrained('administrators', 'id', 'mc_campaigns_updated_by_fk')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'mc_campaigns_status_idx');
            $table->index('starts_at', 'mc_campaigns_starts_at_idx');
            $table->index('ends_at', 'mc_campaigns_ends_at_idx');
            $table->index(['status', 'starts_at', 'ends_at'], 'mc_campaigns_status_validity_idx');
        });

        Schema::create('marketing_campaign_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id')
                ->constrained('marketing_campaigns', 'id', 'mc_links_campaign_fk')
                ->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180)->unique('mc_links_slug_unique');
            $table->string('status', 30)->default('draft');
            $table->string('target_type', 40);
            $table->json('target_payload');
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 160)->nullable();
            $table->string('utm_term', 160)->nullable();
            $table->string('utm_content', 160)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignIdFor(Administrator::class, 'created_by')
                ->nullable()
                ->constrained('administrators', 'id', 'mc_links_created_by_fk')
                ->nullOnDelete();
            $table->foreignIdFor(Administrator::class, 'updated_by')
                ->nullable()
                ->constrained('administrators', 'id', 'mc_links_updated_by_fk')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'mc_links_status_idx');
            $table->index('starts_at', 'mc_links_starts_at_idx');
            $table->index('ends_at', 'mc_links_ends_at_idx');
            $table->index(
                ['marketing_campaign_id', 'status', 'starts_at', 'ends_at'],
                'mc_links_campaign_status_validity_idx'
            );
        });

        Schema::create('marketing_campaign_link_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_link_id')
                ->constrained('marketing_campaign_links', 'id', 'mc_link_aliases_link_fk')
                ->cascadeOnDelete();
            $table->string('slug', 180)->unique('mc_link_aliases_slug_unique');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('marketing_campaign_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id')
                ->constrained('marketing_campaigns', 'id', 'mc_collections_campaign_fk')
                ->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('public_title', 180);
            $table->text('public_description')->nullable();
            $table->string('laboratory_brand', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('marketing_campaign_id', 'mc_collections_campaign_idx');
            $table->index('laboratory_brand', 'mc_collections_brand_idx');
            $table->index('is_active', 'mc_collections_is_active_idx');
        });

        Schema::create('marketing_campaign_collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_collection_id')
                ->constrained('marketing_campaign_collections', 'id', 'mc_collection_items_collection_fk')
                ->cascadeOnDelete();
            $table->foreignIdFor(LaboratoryTest::class)
                ->constrained('laboratory_tests', 'id', 'mc_collection_items_test_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(
                ['marketing_campaign_collection_id', 'laboratory_test_id'],
                'mc_collection_items_unique_test'
            );
            $table->index(
                ['marketing_campaign_collection_id', 'position'],
                'mc_collection_items_position_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_collection_items');
        Schema::dropIfExists('marketing_campaign_collections');
        Schema::dropIfExists('marketing_campaign_link_aliases');
        Schema::dropIfExists('marketing_campaign_links');
        Schema::dropIfExists('marketing_campaigns');
    }
};
