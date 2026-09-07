<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryBillingReportSchedule extends Model
{
    use SoftDeletes;

    public const PERIOD_PREVIOUS_DAY = 'previous_day';
    public const PERIOD_LAST_7_DAYS = 'last_7_days';
    public const PERIOD_CURRENT_WEEK = 'current_week';
    public const PERIOD_PREVIOUS_WEEK = 'previous_week';
    public const PERIOD_CURRENT_MONTH = 'current_month';
    public const PERIOD_PREVIOUS_MONTH = 'previous_month';

    public const SECTION_ACTIVITY = 'activity';
    public const SECTION_BACKLOG = 'backlog';
    public const SECTION_OVERDUE = 'overdue';
    public const SECTION_COMPLETED = 'completed';
    public const SECTION_AGING = 'aging';
    public const SECTION_MISSING_FILES = 'missing_files';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'weekdays' => 'array',
            'filters' => 'array',
            'included_sections' => 'array',
            'recipients' => 'array',
            'include_excel' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(LaboratoryBillingReportRun::class, 'schedule_id');
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(LaboratoryBillingReportRun::class, 'schedule_id')->latestOfMany();
    }

    public static function periodOptions(): array
    {
        return [
            ['value' => self::PERIOD_PREVIOUS_DAY, 'label' => 'Día anterior'],
            ['value' => self::PERIOD_LAST_7_DAYS, 'label' => 'Últimos 7 días'],
            ['value' => self::PERIOD_CURRENT_WEEK, 'label' => 'Semana actual'],
            ['value' => self::PERIOD_PREVIOUS_WEEK, 'label' => 'Semana anterior'],
            ['value' => self::PERIOD_CURRENT_MONTH, 'label' => 'Mes actual'],
            ['value' => self::PERIOD_PREVIOUS_MONTH, 'label' => 'Mes anterior'],
        ];
    }

    public static function sectionOptions(): array
    {
        return [
            ['value' => self::SECTION_ACTIVITY, 'label' => 'Actividad del periodo'],
            ['value' => self::SECTION_BACKLOG, 'label' => 'Pendientes actuales'],
            ['value' => self::SECTION_OVERDUE, 'label' => 'Solicitudes atrasadas'],
            ['value' => self::SECTION_COMPLETED, 'label' => 'Completadas del periodo'],
            ['value' => self::SECTION_AGING, 'label' => 'Antigüedad de pendientes'],
            ['value' => self::SECTION_MISSING_FILES, 'label' => 'Archivos faltantes'],
        ];
    }

    public static function weekdayOptions(): array
    {
        return [
            ['value' => 1, 'label' => 'Lunes'],
            ['value' => 2, 'label' => 'Martes'],
            ['value' => 3, 'label' => 'Miércoles'],
            ['value' => 4, 'label' => 'Jueves'],
            ['value' => 5, 'label' => 'Viernes'],
            ['value' => 6, 'label' => 'Sábado'],
            ['value' => 7, 'label' => 'Domingo'],
        ];
    }
}
