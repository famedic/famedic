<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_attributions', function (Blueprint $table) {
            $table->foreignId('identified_campaign_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('marketing_campaigns', 'id', 'mc_attr_ident_campaign_fk')
                ->restrictOnDelete();
            $table->foreignId('identified_link_id')
                ->nullable()
                ->after('identified_campaign_id')
                ->constrained('marketing_campaign_links', 'id', 'mc_attr_ident_link_fk')
                ->restrictOnDelete();
            $table->foreignId('identified_visit_id')
                ->nullable()
                ->after('identified_link_id')
                ->constrained('marketing_campaign_visits', 'id', 'mc_attr_ident_visit_fk')
                ->restrictOnDelete();
            $table->timestamp('identified_at')->nullable()->after('identified_visit_id');

            $table->index('identified_at', 'mc_attr_identified_at_idx');
            $table->index(['identified_campaign_id', 'identified_at'], 'mc_attr_ident_campaign_at_idx');
            $table->index(['identified_link_id', 'identified_at'], 'mc_attr_ident_link_at_idx');
            $table->index('identified_visit_id', 'mc_attr_ident_visit_idx');
            $table->index(['customer_id', 'identified_at'], 'mc_attr_customer_ident_idx');
        });

        Schema::table('marketing_campaign_visits', function (Blueprint $table) {
            $table->index(['marketing_campaign_id', 'visitor_token_hash'], 'mc_visits_campaign_token_idx');
            $table->index(['marketing_campaign_link_id', 'visitor_token_hash'], 'mc_visits_link_token_idx');
            $table->index(['utm_source', 'visited_at'], 'mc_visits_utm_source_at_idx');
            $table->index(['utm_medium', 'visited_at'], 'mc_visits_utm_medium_at_idx');
        });

        Schema::table('marketing_campaign_conversions', function (Blueprint $table) {
            $table->index(['first_campaign_id', 'converted_at'], 'mc_conv_first_campaign_at_idx');
            $table->index(['first_link_id', 'converted_at'], 'mc_conv_first_link_at_idx');
            $table->index(['utm_source', 'converted_at'], 'mc_conv_utm_source_at_idx');
            $table->index(['utm_medium', 'converted_at'], 'mc_conv_utm_medium_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaign_conversions', function (Blueprint $table) {
            $table->dropIndex('mc_conv_first_campaign_at_idx');
            $table->dropIndex('mc_conv_first_link_at_idx');
            $table->dropIndex('mc_conv_utm_source_at_idx');
            $table->dropIndex('mc_conv_utm_medium_at_idx');
        });

        Schema::table('marketing_campaign_visits', function (Blueprint $table) {
            $table->dropIndex('mc_visits_campaign_token_idx');
            $table->dropIndex('mc_visits_link_token_idx');
            $table->dropIndex('mc_visits_utm_source_at_idx');
            $table->dropIndex('mc_visits_utm_medium_at_idx');
        });

        Schema::table('marketing_campaign_attributions', function (Blueprint $table) {
            $table->dropForeign('mc_attr_ident_campaign_fk');
            $table->dropForeign('mc_attr_ident_link_fk');
            $table->dropForeign('mc_attr_ident_visit_fk');
            $table->dropIndex('mc_attr_identified_at_idx');
            $table->dropIndex('mc_attr_ident_campaign_at_idx');
            $table->dropIndex('mc_attr_ident_link_at_idx');
            $table->dropIndex('mc_attr_ident_visit_idx');
            $table->dropIndex('mc_attr_customer_ident_idx');
            $table->dropColumn([
                'identified_campaign_id',
                'identified_link_id',
                'identified_visit_id',
                'identified_at',
            ]);
        });
    }
};
