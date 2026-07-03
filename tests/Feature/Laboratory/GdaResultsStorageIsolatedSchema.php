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
            'lab_order_event_receipts',
            'lab_order_event_states',
            'laboratory_notifications',
            'laboratory_purchase_items',
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

        Schema::create('laboratory_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->constrained();
            $table->string('gda_id')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->timestamps();
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
            $table->timestamp('email_sent_at')->nullable();
            $table->unsignedBigInteger('email_recipient_id')->nullable();
            $table->string('email_recipient_email')->nullable();
            $table->text('email_error')->nullable();
            $table->timestamp('email_attempted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
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

        Schema::enableForeignKeyConstraints();
    }

    protected function tearDownIsolatedSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'lab_order_event_receipts',
            'lab_order_event_states',
            'laboratory_notifications',
            'laboratory_purchase_items',
            'laboratory_purchases',
            'customers',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
}
