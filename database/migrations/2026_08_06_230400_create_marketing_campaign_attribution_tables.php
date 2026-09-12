<?php

use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaign_attributions', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_token_hash', 64);
            $table->foreignId('first_campaign_id')
                ->constrained('marketing_campaigns', 'id', 'mc_attr_first_campaign_fk')
                ->restrictOnDelete();
            $table->foreignId('first_link_id')
                ->constrained('marketing_campaign_links', 'id', 'mc_attr_first_link_fk')
                ->restrictOnDelete();
            $table->foreignId('last_campaign_id')
                ->constrained('marketing_campaigns', 'id', 'mc_attr_last_campaign_fk')
                ->restrictOnDelete();
            $table->foreignId('last_link_id')
                ->constrained('marketing_campaign_links', 'id', 'mc_attr_last_link_fk')
                ->restrictOnDelete();
            $table->timestamp('first_touched_at');
            $table->timestamp('last_touched_at');
            $table->timestamp('expires_at');
            $table->foreignIdFor(User::class)
                ->nullable()
                ->constrained('users', 'id', 'mc_attr_user_fk')
                ->nullOnDelete();
            $table->foreignIdFor(Customer::class)
                ->nullable()
                ->constrained('customers', 'id', 'mc_attr_customer_fk')
                ->nullOnDelete();
            $table->timestamps();

            $table->index('visitor_token_hash', 'mc_attr_token_hash_idx');
            $table->index('expires_at', 'mc_attr_expires_at_idx');
            $table->index(['visitor_token_hash', 'expires_at'], 'mc_attr_token_expires_idx');
            $table->index(['last_campaign_id', 'last_touched_at'], 'mc_attr_last_campaign_touch_idx');
            $table->index(['last_link_id', 'last_touched_at'], 'mc_attr_last_link_touch_idx');
        });

        Schema::create('marketing_campaign_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id')
                ->constrained('marketing_campaigns', 'id', 'mc_visits_campaign_fk')
                ->restrictOnDelete();
            $table->foreignId('marketing_campaign_link_id')
                ->constrained('marketing_campaign_links', 'id', 'mc_visits_link_fk')
                ->restrictOnDelete();
            $table->foreignId('marketing_campaign_attribution_id')
                ->nullable()
                ->constrained('marketing_campaign_attributions', 'id', 'mc_visits_attribution_fk')
                ->restrictOnDelete();
            $table->string('visitor_token_hash', 64);
            $table->foreignIdFor(User::class)
                ->nullable()
                ->constrained('users', 'id', 'mc_visits_user_fk')
                ->nullOnDelete();
            $table->foreignIdFor(Customer::class)
                ->nullable()
                ->constrained('customers', 'id', 'mc_visits_customer_fk')
                ->nullOnDelete();
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->string('gclid', 255)->nullable();
            $table->string('fbclid', 255)->nullable();
            $table->string('landing_path', 255);
            $table->string('referrer_host', 255)->nullable();
            $table->timestamp('visited_at');
            $table->timestamp('created_at')->nullable();

            $table->index('visitor_token_hash', 'mc_visits_token_hash_idx');
            $table->index(['marketing_campaign_id', 'visited_at'], 'mc_visits_campaign_visited_idx');
            $table->index(['marketing_campaign_link_id', 'visited_at'], 'mc_visits_link_visited_idx');
            $table->index('marketing_campaign_attribution_id', 'mc_visits_attribution_idx');
            $table->index('visited_at', 'mc_visits_visited_at_idx');
        });

        Schema::table('marketing_campaign_attributions', function (Blueprint $table) {
            $table->foreignId('first_visit_id')
                ->nullable()
                ->after('visitor_token_hash')
                ->constrained('marketing_campaign_visits', 'id', 'mc_attr_first_visit_fk')
                ->restrictOnDelete();
            $table->foreignId('last_visit_id')
                ->nullable()
                ->after('first_visit_id')
                ->constrained('marketing_campaign_visits', 'id', 'mc_attr_last_visit_fk')
                ->restrictOnDelete();

            $table->index('first_visit_id', 'mc_attr_first_visit_idx');
            $table->index('last_visit_id', 'mc_attr_last_visit_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaign_attributions', function (Blueprint $table) {
            $table->dropForeign('mc_attr_first_visit_fk');
            $table->dropForeign('mc_attr_last_visit_fk');
            $table->dropIndex('mc_attr_first_visit_idx');
            $table->dropIndex('mc_attr_last_visit_idx');
            $table->dropColumn(['first_visit_id', 'last_visit_id']);
        });

        Schema::dropIfExists('marketing_campaign_visits');
        Schema::dropIfExists('marketing_campaign_attributions');
    }
};
