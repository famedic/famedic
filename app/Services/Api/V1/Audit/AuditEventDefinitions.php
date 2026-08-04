<?php

namespace App\Services\Api\V1\Audit;

/**
 * Explicit allowlists of metadata keys per event_name.
 *
 * Unknown event names receive an empty allowlist (all keys stripped).
 * Metadata keys intentionally avoid sensitive substrings (code/key/grant/otp/token)
 * so the name-based redaction defense does not strip legitimate fields.
 */
final class AuditEventDefinitions
{
    public const PREFIX = 'api_v1.';

    /** Infrastructure probe — used by tests; not a business event. */
    public const EVENT_INFRA_PROBE = 'api_v1.audit.infra_probe';

    public const EVENT_INFRA_WRITER_PROBE = 'api_v1.audit.writer_probe';

    // ── Auth / OTP (Block 2) ─────────────────────────────────────────────

    public const EVENT_LOGIN_CODE_REQUESTED = 'api_v1.auth.login_code_requested';

    public const EVENT_LOGIN_CODE_RESENT = 'api_v1.auth.login_code_resent';

    public const EVENT_LOGIN_VERIFIED = 'api_v1.auth.login_verified';

    public const EVENT_REGISTRATION_CODE_REQUESTED = 'api_v1.auth.registration_code_requested';

    public const EVENT_REGISTRATION_CODE_RESENT = 'api_v1.auth.registration_code_resent';

    public const EVENT_REGISTRATION_COMPLETED = 'api_v1.auth.registration_completed';

    public const EVENT_STEP_UP_REQUESTED = 'api_v1.otp.step_up_requested';

    public const EVENT_STEP_UP_VERIFIED = 'api_v1.otp.step_up_verified';

    // ── Secure links / downloads (Block 3) ────────────────────────────────

    public const EVENT_RESULTS_SECURE_LINK_CREATED = 'api_v1.results.secure_link_created';

    public const EVENT_RESULTS_SECURE_LINK_OPENED = 'api_v1.results.secure_link_opened';

    public const EVENT_RESULTS_DOWNLOADED = 'api_v1.results.downloaded';

    public const EVENT_INVOICES_SECURE_LINK_CREATED = 'api_v1.invoices.secure_link_created';

    public const EVENT_INVOICES_SECURE_LINK_OPENED = 'api_v1.invoices.secure_link_opened';

    public const EVENT_INVOICES_DOWNLOADED = 'api_v1.invoices.downloaded';

    // ── Cart / checkout draft (Block 4) ───────────────────────────────────

    public const EVENT_CART_ITEM_ADDED = 'api_v1.cart.item_added';

    public const EVENT_CART_ITEM_REMOVED = 'api_v1.cart.item_removed';

    public const EVENT_CART_CLEARED = 'api_v1.cart.cleared';

    public const EVENT_CART_BENEFIT_APPLIED = 'api_v1.cart.benefit_applied';

    public const EVENT_CART_BENEFIT_REMOVED = 'api_v1.cart.benefit_removed';

    public const EVENT_CHECKOUT_DRAFT_SYNCED = 'api_v1.checkout.draft_synced';

    // ── Appointments / callback preference (Block 5) ──────────────────────

    public const EVENT_APPOINTMENTS_REQUESTED = 'api_v1.appointments.requested';

    public const EVENT_APPOINTMENTS_CANCELLED = 'api_v1.appointments.cancelled';

    /**
     * Shared metadata keys for OTP Auth events (all allowlisted names avoid
     * sensitive tokens: code, key, grant, otp, token, password, …).
     *
     * @var list<string>
     */
    private const AUTH_OTP_METADATA = [
        'delivery_channel',
        'delivery_result_class',
        'is_resend',
        'is_decoy',
        'challenge_row_id',
        'session_issued',
        'purpose',
        'order_row_id',
        'laboratory_purchase_row_id',
        'invoice_row_id',
        'step_up_row_id',
    ];

    /**
     * Metadata for secure-link creation (results / invoices).
     * Keys avoid sensitive substrings (token/grant/key/code/…).
     * `secure_link_row_id` is allowlisted: exact forbidden key is `secure_link` only.
     *
     * @var list<string>
     */
    private const DOCUMENT_SECURE_LINK_CREATED_METADATA = [
        'purpose',
        'secure_link_row_id',
        'step_up_row_id',
        'laboratory_purchase_row_id',
        'order_row_id',
        'invoice_row_id',
        'ttl_minutes',
        'max_opens',
    ];

    /**
     * Metadata for public secure-link open.
     *
     * @var list<string>
     */
    private const DOCUMENT_SECURE_LINK_OPENED_METADATA = [
        'purpose',
        'secure_link_row_id',
        'step_up_row_id',
        'laboratory_purchase_row_id',
        'order_row_id',
        'invoice_row_id',
        'open_number',
        'max_opens',
    ];

    /**
     * Metadata for Bearer PDF downloads.
     *
     * @var list<string>
     */
    private const DOCUMENT_DOWNLOADED_METADATA = [
        'purpose',
        'step_up_row_id',
        'laboratory_purchase_row_id',
        'order_row_id',
        'invoice_row_id',
    ];

    /**
     * Cart item mutations. Keys avoid sensitive substrings (code/token/key/…).
     *
     * @var list<string>
     */
    private const CART_ITEM_METADATA = [
        'laboratory_brand',
        'cart_item_row_id',
        'laboratory_test_row_id',
        'item_count',
        'quantity',
    ];

    /**
     * Cart-level clear.
     *
     * @var list<string>
     */
    private const CART_CLEARED_METADATA = [
        'laboratory_brand',
        'item_count',
    ];

    /**
     * Coupon / balance benefit on cart draft. Never coupon_code / promo_code.
     *
     * @var list<string>
     */
    private const CART_BENEFIT_METADATA = [
        'laboratory_brand',
        'benefit_type',
        'coupon_row_id',
        'applied_amount_minor',
        'removed_amount_minor',
        'item_count',
        'subtotal_minor',
        'discount_minor',
        'credit_minor',
        'total_minor',
        'currency',
    ];

    /**
     * Checkout draft sync (contact/address IDs intentionally omitted).
     *
     * @var list<string>
     */
    private const CHECKOUT_DRAFT_METADATA = [
        'laboratory_brand',
        'draft_row_id',
        'checkout_step',
        'item_count',
        'checkout_ready',
    ];

    /**
     * Laboratory appointment request / cancel (Block 5).
     * Never notes, phone, patient fields, exact clock time, or free-form windows.
     *
     * @var list<string>
     */
    private const APPOINTMENT_METADATA = [
        'laboratory_brand',
        'appointment_row_id',
        'appointment_state',
        'previous_state',
        'resulting_state',
        'scheduling_mode',
        'request_channel',
        'requested_date',
        'requested_window',
        'timezone',
        'checkout_draft_advanced',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ALLOWLISTS = [
        self::EVENT_INFRA_PROBE => [
            'probe_id',
            'suite',
            'flags',
            'count',
            'labels',
        ],
        self::EVENT_INFRA_WRITER_PROBE => [
            'probe_id',
            'phase',
            'ok',
        ],
        self::EVENT_LOGIN_CODE_REQUESTED => self::AUTH_OTP_METADATA,
        self::EVENT_LOGIN_CODE_RESENT => self::AUTH_OTP_METADATA,
        self::EVENT_LOGIN_VERIFIED => self::AUTH_OTP_METADATA,
        self::EVENT_REGISTRATION_CODE_REQUESTED => self::AUTH_OTP_METADATA,
        self::EVENT_REGISTRATION_CODE_RESENT => self::AUTH_OTP_METADATA,
        self::EVENT_REGISTRATION_COMPLETED => self::AUTH_OTP_METADATA,
        self::EVENT_STEP_UP_REQUESTED => self::AUTH_OTP_METADATA,
        self::EVENT_STEP_UP_VERIFIED => self::AUTH_OTP_METADATA,
        self::EVENT_RESULTS_SECURE_LINK_CREATED => self::DOCUMENT_SECURE_LINK_CREATED_METADATA,
        self::EVENT_RESULTS_SECURE_LINK_OPENED => self::DOCUMENT_SECURE_LINK_OPENED_METADATA,
        self::EVENT_RESULTS_DOWNLOADED => self::DOCUMENT_DOWNLOADED_METADATA,
        self::EVENT_INVOICES_SECURE_LINK_CREATED => self::DOCUMENT_SECURE_LINK_CREATED_METADATA,
        self::EVENT_INVOICES_SECURE_LINK_OPENED => self::DOCUMENT_SECURE_LINK_OPENED_METADATA,
        self::EVENT_INVOICES_DOWNLOADED => self::DOCUMENT_DOWNLOADED_METADATA,
        self::EVENT_CART_ITEM_ADDED => self::CART_ITEM_METADATA,
        self::EVENT_CART_ITEM_REMOVED => self::CART_ITEM_METADATA,
        self::EVENT_CART_CLEARED => self::CART_CLEARED_METADATA,
        self::EVENT_CART_BENEFIT_APPLIED => self::CART_BENEFIT_METADATA,
        self::EVENT_CART_BENEFIT_REMOVED => self::CART_BENEFIT_METADATA,
        self::EVENT_CHECKOUT_DRAFT_SYNCED => self::CHECKOUT_DRAFT_METADATA,
        self::EVENT_APPOINTMENTS_REQUESTED => self::APPOINTMENT_METADATA,
        self::EVENT_APPOINTMENTS_CANCELLED => self::APPOINTMENT_METADATA,
    ];

    /**
     * @return list<string>
     */
    public static function allowedMetadataKeys(string $eventName): array
    {
        return self::ALLOWLISTS[$eventName] ?? [];
    }

    public static function isKnownEvent(string $eventName): bool
    {
        return array_key_exists($eventName, self::ALLOWLISTS);
    }

    /**
     * @return list<string>
     */
    public static function knownEventNames(): array
    {
        return array_keys(self::ALLOWLISTS);
    }

    /**
     * @return list<string>
     */
    public static function authOtpEventNames(): array
    {
        return [
            self::EVENT_LOGIN_CODE_REQUESTED,
            self::EVENT_LOGIN_CODE_RESENT,
            self::EVENT_LOGIN_VERIFIED,
            self::EVENT_REGISTRATION_CODE_REQUESTED,
            self::EVENT_REGISTRATION_CODE_RESENT,
            self::EVENT_REGISTRATION_COMPLETED,
            self::EVENT_STEP_UP_REQUESTED,
            self::EVENT_STEP_UP_VERIFIED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function documentAccessEventNames(): array
    {
        return [
            self::EVENT_RESULTS_SECURE_LINK_CREATED,
            self::EVENT_RESULTS_SECURE_LINK_OPENED,
            self::EVENT_RESULTS_DOWNLOADED,
            self::EVENT_INVOICES_SECURE_LINK_CREATED,
            self::EVENT_INVOICES_SECURE_LINK_OPENED,
            self::EVENT_INVOICES_DOWNLOADED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function cartCheckoutEventNames(): array
    {
        return [
            self::EVENT_CART_ITEM_ADDED,
            self::EVENT_CART_ITEM_REMOVED,
            self::EVENT_CART_CLEARED,
            self::EVENT_CART_BENEFIT_APPLIED,
            self::EVENT_CART_BENEFIT_REMOVED,
            self::EVENT_CHECKOUT_DRAFT_SYNCED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function appointmentConciergeEventNames(): array
    {
        return [
            self::EVENT_APPOINTMENTS_REQUESTED,
            self::EVENT_APPOINTMENTS_CANCELLED,
        ];
    }
}
