<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OdessaPreEnrollment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_READY = 'READY';
    public const STATUS_BLOCKED = 'BLOCKED';
    public const STATUS_ARCHIVED = 'ARCHIVED';

    public const LINK_PENDING_ACCOUNT = 'PENDING_ACCOUNT';
    public const LINK_CANDIDATE_FOUND = 'CANDIDATE_FOUND';
    public const LINK_LINKED = 'LINKED';
    public const LINK_IDENTITY_CONFLICT = 'IDENTITY_CONFLICT';
    public const LINK_POSSIBLE_DUPLICATE = 'POSSIBLE_DUPLICATE';

    public const MURGUIA_NOT_REQUESTED = 'NOT_REQUESTED';
    public const MURGUIA_PENDING = 'PENDING';
    public const MURGUIA_ACTIVE = 'ACTIVE';
    public const MURGUIA_INACTIVE = 'INACTIVE';
    public const MURGUIA_FAILED = 'FAILED';

    public const ACTION_ALTA = 'ALTA';
    public const ACTION_HISTORICO = 'HISTORICO';
    public const ACTION_BAJA = 'BAJA';
    public const ACTION_NONE = 'NONE';

    protected $guarded = [];

    protected $hidden = [
        'source_file_hash',
        'source_row_hash',
        'source_snapshot_json',
        'metadata_json',
        'medical_attention_identifier',
        'murguia_correlation_id',
        'murguia_operation_token',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'membership_start_date' => 'date',
            'membership_end_date' => 'date',
            'murguia_synced_at' => 'datetime',
            'murguia_pending_since' => 'datetime',
            'murguia_registration_acknowledged_at' => 'datetime',
            'murguia_checked_at' => 'datetime',
            'linked_at' => 'datetime',
            'data_quality_flags' => 'array',
            'source_snapshot_json' => 'array',
            'metadata_json' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (self $preEnrollment) {
            $preEnrollment->uuid ??= (string) Str::uuid();
            $preEnrollment->refreshActiveKeys();
        });

        static::saving(function (self $preEnrollment) {
            $preEnrollment->refreshActiveKeys();
        });
    }

    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_READY, self::STATUS_BLOCKED, self::STATUS_ARCHIVED];
    }

    public static function linkStatuses(): array
    {
        return [self::LINK_PENDING_ACCOUNT, self::LINK_CANDIDATE_FOUND, self::LINK_LINKED, self::LINK_IDENTITY_CONFLICT, self::LINK_POSSIBLE_DUPLICATE];
    }

    public static function murguiaStatuses(): array
    {
        return [self::MURGUIA_NOT_REQUESTED, self::MURGUIA_PENDING, self::MURGUIA_ACTIVE, self::MURGUIA_INACTIVE, self::MURGUIA_FAILED];
    }

    public static function sourceActions(): array
    {
        return [self::ACTION_ALTA, self::ACTION_HISTORICO, self::ACTION_BAJA, self::ACTION_NONE];
    }

    public function refreshActiveKeys(): void
    {
        if ($this->status === self::STATUS_ARCHIVED) {
            $this->active_company_employee_key = null;
            $this->active_odessa_identifier = null;

            return;
        }

        $company = self::normalizeIdentifier($this->company_external_identifier);
        $employee = self::normalizeIdentifier($this->employee_identifier);
        $this->active_company_employee_key = $company && $employee ? "{$company}|{$employee}" : null;
        $this->active_odessa_identifier = self::normalizeIdentifier($this->odessa_identifier);
    }

    public function scopeFilter(Builder $query, array $filters, bool $canSearchSensitiveIdentity = false): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function (Builder $query, string $search) use ($canSearchSensitiveIdentity) {
                $search = trim($search);
                if ($search === '') {
                    return;
                }

                $query->where(function (Builder $q) use ($search, $canSearchSensitiveIdentity) {
                    $q->where('uuid', 'like', "%{$search}%")
                        ->orWhere('source_action', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('link_status', 'like', "%{$search}%")
                        ->orWhere('murguia_status', 'like', "%{$search}%");

                    if (is_numeric($search)) {
                        $q->orWhere('source_row', (int) $search);
                    }

                    if ($canSearchSensitiveIdentity) {
                        $q->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('paternal_last_name', 'like', "%{$search}%")
                            ->orWhere('maternal_last_name', 'like', "%{$search}%")
                            ->orWhere('source_email', 'like', "%{$search}%")
                            ->orWhere('employee_identifier', 'like', "%{$search}%")
                            ->orWhere('company_external_identifier', 'like', "%{$search}%")
                            ->orWhere('odessa_identifier', 'like', "%{$search}%")
                            ->orWhere('medical_attention_identifier', 'like', "%{$search}%");
                    }
                });
            })
            ->when($filters['source_action'] ?? null, fn (Builder $q, string $value) => $q->where('source_action', $value))
            ->when($filters['status'] ?? null, fn (Builder $q, string $value) => $q->where('status', $value))
            ->when($filters['link_status'] ?? null, fn (Builder $q, string $value) => $q->where('link_status', $value))
            ->when($filters['murguia_status'] ?? null, fn (Builder $q, string $value) => $q->where('murguia_status', $value))
            ->when($canSearchSensitiveIdentity && ($filters['credit'] ?? null) === 'with', fn (Builder $q) => $q->whereNotNull('medical_attention_identifier'))
            ->when($canSearchSensitiveIdentity && ($filters['credit'] ?? null) === 'without', fn (Builder $q) => $q->whereNull('medical_attention_identifier'))
            ->when($filters['flag'] ?? null, fn (Builder $q, string $flag) => $q->whereJsonContains('data_quality_flags', $flag));
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    public function linkedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'linked_customer_id');
    }

    public function linkedOdessaAccount(): BelongsTo
    {
        return $this->belongsTo(OdessaAfiliateAccount::class, 'linked_odessa_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(OdessaPreEnrollmentImportRun::class, 'import_run_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(OdessaPreEnrollmentAudit::class);
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => trim(implode(' ', array_filter([
                $this->first_name,
                $this->paternal_last_name,
                $this->maternal_last_name,
            ]))),
        );
    }

    public static function normalizeIdentifier(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
