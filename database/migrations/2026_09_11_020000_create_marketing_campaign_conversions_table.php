<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaign_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_attribution_id')
                ->constrained('marketing_campaign_attributions', 'id', 'mc_conv_attr_fk')
                ->restrictOnDelete();
            $table->foreignId('marketing_campaign_visitor_identity_id')
                ->nullable()
                ->constrained('marketing_campaign_visitor_identities', 'id', 'mc_conv_vid_fk')
                ->restrictOnDelete();
            $table->foreignId('first_campaign_id')
                ->constrained('marketing_campaigns', 'id', 'mc_conv_first_campaign_fk')
                ->restrictOnDelete();
            $table->foreignId('first_link_id')
                ->constrained('marketing_campaign_links', 'id', 'mc_conv_first_link_fk')
                ->restrictOnDelete();
            $table->foreignId('first_visit_id')
                ->nullable()
                ->constrained('marketing_campaign_visits', 'id', 'mc_conv_first_visit_fk')
                ->restrictOnDelete();
            $table->foreignId('last_campaign_id')
                ->constrained('marketing_campaigns', 'id', 'mc_conv_last_campaign_fk')
                ->restrictOnDelete();
            $table->foreignId('last_link_id')
                ->constrained('marketing_campaign_links', 'id', 'mc_conv_last_link_fk')
                ->restrictOnDelete();
            $table->foreignId('last_visit_id')
                ->constrained('marketing_campaign_visits', 'id', 'mc_conv_last_visit_fk')
                ->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('conversion_type', 80);
            $table->foreignId('purchase_id')
                ->constrained('laboratory_purchases', 'id', 'mc_conv_purchase_fk')
                ->restrictOnDelete();
            $table->string('currency', 3);
            $table->unsignedInteger('amount_cents');
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->string('gclid', 255)->nullable();
            $table->string('fbclid', 255)->nullable();
            $table->timestamp('converted_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['conversion_type', 'purchase_id'], 'mc_conv_type_purchase_unique');
            $table->index('user_id', 'mc_conv_user_idx');
            $table->index(['customer_id', 'converted_at'], 'mc_conv_customer_at_idx');
            $table->index(['last_campaign_id', 'converted_at'], 'mc_conv_campaign_at_idx');
            $table->index(['last_link_id', 'converted_at'], 'mc_conv_link_at_idx');
            $table->index('converted_at', 'mc_conv_converted_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_conversions');
    }
};
