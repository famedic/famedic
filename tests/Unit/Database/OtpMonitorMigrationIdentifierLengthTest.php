<?php

/**
 * Guard against MySQL error 1059 (identifier name > 64 chars) on OTP monitor migrations.
 *
 * Keep in sync with public const on:
 * - 2026_09_08_140000_add_vonage_sms_delivery_tracking.php
 * - 2026_09_08_150000_create_otp_sms_delivery_receipt_applications_table.php
 * - 2026_09_08_120000_create_otp_movement_events_table.php (auto-names audited below)
 */
test('identificadores SQL explicitos de migraciones OTP/DLR no exceden 64 caracteres', function () {
    $identifiers = [
        // 2026_09_08_150000 — otp_sms_delivery_receipt_applications
        'otp_dlr_apps_receipt_fk',
        'otp_dlr_apps_operation_fk',
        'otp_dlr_apps_receipt_uq',
        'otp_dlr_apps_operation_idx',
        'otp_dlr_apps_applied_idx',
        // 2026_09_08_140000 — otp_sms_delivery_receipts + otp_delivery_operations columns
        'otp_dlr_receipts_op_fk',
        'otp_dlr_receipts_msg_idx',
        'otp_dlr_receipts_op_idx',
        'otp_dlr_receipts_status_idx',
        'otp_dlr_receipts_idem_uq',
        'otp_dlr_receipts_recv_idx',
        'otp_dlr_ops_msg_idx',
        'otp_dlr_ops_sms_st_idx',
    ];

    foreach ($identifiers as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(64, "Identifier too long: {$name}");
    }
});

test('nombres auto-generados por Laravel en migracion otp_movement_events estan bajo 64 caracteres', function () {
    $table = 'otp_movement_events';

    $autoNames = [
        "{$table}_user_id_foreign",
        "{$table}_customer_id_foreign",
        "{$table}_otp_challenge_id_foreign",
        "{$table}_otp_delivery_operation_id_foreign",
        "{$table}_occurred_at_flow_index",
        "{$table}_occurred_at_status_index",
        "{$table}_occurred_at_index",
        "{$table}_movement_key_index",
    ];

    foreach ($autoNames as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(64, "Auto identifier too long: {$name}");
    }
});

test('nombres auto-generados legacy que fallaron en staging exceden 64 y no deben reutilizarse', function () {
    $failedLegacy = 'otp_sms_delivery_receipt_applications_otp_sms_delivery_receipt_id_foreign';

    expect(strlen($failedLegacy))->toBeGreaterThan(64);
});

test('constantes publicas en migraciones 140000 y 150000 coinciden con manifest de prueba', function () {
    $appsMigration = require database_path('migrations/2026_09_08_150000_create_otp_sms_delivery_receipt_applications_table.php');
    $trackingMigration = require database_path('migrations/2026_09_08_140000_add_vonage_sms_delivery_tracking.php');

    expect($appsMigration::FK_RECEIPT)->toBe('otp_dlr_apps_receipt_fk');
    expect($appsMigration::FK_OPERATION)->toBe('otp_dlr_apps_operation_fk');
    expect($appsMigration::UQ_RECEIPT)->toBe('otp_dlr_apps_receipt_uq');
    expect($trackingMigration::FK_OPERATION)->toBe('otp_dlr_receipts_op_fk');
    expect($trackingMigration::UQ_IDEMPOTENCY)->toBe('otp_dlr_receipts_idem_uq');
});
