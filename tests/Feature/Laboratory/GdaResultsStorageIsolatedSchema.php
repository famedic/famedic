<?php

namespace Tests\Feature\Laboratory;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait GdaResultsStorageIsolatedSchema
{
    protected function bootstrapIsolatedSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'laboratory_result_ai_explanations',
            'customer_laboratory_ai_explanation_consents',
            'laboratory_result_observations',
            'laboratory_result_reports',
            'laboratory_result_extraction_qa_metrics',
            'laboratory_analyte_aliases',
            'laboratory_analytes',
            'ai_executions',
            'ai_prompts',
            'laboratory_result_events',
            'laboratory_result_versions',
            'laboratory_result_statuses',
            'activecampaign_dispatches',
            'lab_order_event_receipts',
            'lab_order_event_states',
            'laboratory_notifications',
            'laboratory_purchase_items',
            'laboratory_quotes',
            'laboratory_purchases',
            'customers',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('stripe_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('brand')->default('olab');
            $table->string('gda_order_id')->nullable();
            $table->bigInteger('gda_consecutivo')->nullable();
            $table->string('gda_acuse')->nullable();
            $table->json('gda_response')->nullable();
            $table->string('gda_code_http')->nullable();
            $table->string('gda_mensaje')->nullable();
            $table->text('gda_description')->nullable();
            $table->longText('pdf_base64')->nullable();
            $table->string('results')->nullable();
            $table->string('name');
            $table->string('paternal_lastname');
            $table->string('maternal_lastname');
            $table->string('phone');
            $table->string('phone_country')->default('MX');
            $table->date('birth_date');
            $table->string('gender')->nullable();
            $table->string('street');
            $table->string('number');
            $table->string('neighborhood');
            $table->string('state');
            $table->string('city');
            $table->string('zipcode');
            $table->unsignedInteger('total_cents')->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('results_downloaded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_quotes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('results_downloaded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->constrained();
            $table->string('gda_id')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_result_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->constrained('laboratory_purchases')->cascadeOnDelete();
            $table->foreignId('laboratory_purchase_item_id')->constrained('laboratory_purchase_items')->cascadeOnDelete();
            $table->string('status')->default('not_available');
            $table->timestamp('first_available_at')->nullable();
            $table->timestamp('interpreted_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedInteger('check_attempts')->default(0);
            $table->timestamp('completed_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['laboratory_purchase_id', 'laboratory_purchase_item_id'], 'lab_result_status_purchase_item_unique');
            $table->index(['status', 'next_check_at'], 'lab_result_status_next_check_index');
            $table->index('first_available_at', 'lab_result_status_first_available_index');
        });

        Schema::create('laboratory_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->nullable();
            $table->unsignedBigInteger('laboratory_quote_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('gda_order_id')->nullable();
            $table->bigInteger('gda_consecutivo')->nullable();
            $table->string('gda_external_id')->nullable();
            $table->string('gda_acuse')->nullable();
            $table->string('notification_type');
            $table->string('status');
            $table->string('gda_status')->nullable();
            $table->string('resource_type')->nullable();
            $table->string('lineanegocio')->nullable();
            $table->json('payload');
            $table->json('gda_message')->nullable();
            $table->longText('results_pdf_base64')->nullable();
            $table->timestamp('results_received_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->unsignedBigInteger('email_recipient_id')->nullable();
            $table->string('email_recipient_email')->nullable();
            $table->text('email_error')->nullable();
            $table->timestamp('email_attempted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('laboratory_result_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_result_status_id')->constrained('laboratory_result_statuses')->cascadeOnDelete();
            $table->foreignId('laboratory_notification_id')->nullable()->constrained('laboratory_notifications')->nullOnDelete();
            $table->string('storage_path');
            $table->string('sha256', 64);
            $table->string('source', 40)->default('gda');
            $table->string('classification')->default('unknown');
            $table->string('classification_reason')->nullable();
            $table->string('matched_rule')->nullable();
            $table->string('classifier')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamp('pdf_available_at')->nullable();
            $table->timestamps();

            $table->unique(['laboratory_result_status_id', 'sha256'], 'lab_result_version_status_sha_unique');
            $table->index('sha256', 'lab_result_version_sha_index');
            $table->index(['classification', 'classified_at'], 'lab_result_version_classified_index');
        });

        Schema::create('laboratory_result_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_result_status_id')->constrained('laboratory_result_statuses')->cascadeOnDelete();
            $table->foreignId('laboratory_result_version_id')->nullable()->constrained('laboratory_result_versions')->nullOnDelete();
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('actor_type')->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['laboratory_result_status_id', 'event_type'], 'lab_result_event_status_type_index');
            $table->index('created_at', 'lab_result_event_created_at_index');
        });

        Schema::create('lab_order_event_states', function (Blueprint $table) {
            $table->id();
            $table->string('gda_order_id')->unique();
            $table->foreignId('laboratory_purchase_id')->nullable()->constrained('laboratory_purchases')->nullOnDelete();
            $table->unsignedInteger('total_studies')->default(0);
            $table->unsignedInteger('sample_received_count')->default(0);
            $table->unsignedInteger('results_received_count')->default(0);
            $table->timestamp('sample_email_sent_at')->nullable();
            $table->timestamp('results_email_sent_at')->nullable();
            $table->timestamp('sample_tag_sent_at')->nullable();
            $table->timestamp('results_tag_sent_at')->nullable();
            $table->timestamp('first_event_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lab_order_event_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_event_state_id')->constrained('lab_order_event_states')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('study_external_id')->nullable();
            $table->string('provider_event_id')->nullable()->unique();
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['lab_order_event_state_id', 'event_type', 'study_external_id'], 'lab_evt_receipt_state_type_study_unique');
            $table->unique(['lab_order_event_state_id', 'event_type', 'payload_hash'], 'lab_evt_receipt_state_type_hash_unique');
        });

        Schema::create('activecampaign_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('related_entity_type', 64)->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('email')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'status']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('user_id');
            $table->index('customer_id');
            $table->index('email');
            $table->index('synced_at');
        });

        Schema::enableForeignKeyConstraints();
    }

    protected function tearDownIsolatedSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'laboratory_result_ai_explanations',
            'customer_laboratory_ai_explanation_consents',
            'laboratory_result_observations',
            'laboratory_result_reports',
            'laboratory_result_extraction_qa_metrics',
            'laboratory_analyte_aliases',
            'laboratory_analytes',
            'ai_executions',
            'ai_prompts',
            'laboratory_result_events',
            'laboratory_result_versions',
            'laboratory_result_statuses',
            'activecampaign_dispatches',
            'lab_order_event_receipts',
            'lab_order_event_states',
            'laboratory_notifications',
            'laboratory_purchase_items',
            'laboratory_quotes',
            'laboratory_purchases',
            'customers',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
}
