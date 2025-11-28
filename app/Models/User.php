<?php

namespace App\Models;

use App\Enums\Gender;
use App\Interfaces\MustVerifyPhone;
use App\Traits\MustVerifyPhone as TraitsMustVerifyPhone;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Propaganistas\LaravelPhone\Casts\RawPhoneNumberCast;

class User extends Authenticatable implements MustVerifyEmail, MustVerifyPhone
{
    use HasFactory, Notifiable, TraitsMustVerifyPhone;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'profile_photo_url',
        'birth_date_string',
        'full_name',
        'full_phone',
        'formatted_birth_date',
        'formatted_gender',
        'profile_is_complete',        // ya lo tenías
        'pending_results_count',      // ← nuevo
        'unread_lab_notifications_count', // ← nuevo
        'has_pending_lab_results',    // ← nuevo
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'documentation_accepted_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'gender' => Gender::class,
            'phone' => RawPhoneNumberCast::class . ':country_field',
        ];
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function administrator(): HasOne
    {
        return $this->hasOne(Administrator::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Check if full_name has actual content (not just spaces)
                $fullName = trim($this->full_name);
                $displayName = $fullName ?: $this->email;

                if (!$displayName) {
                    return 'https://ui-avatars.com/api/?name=U&color=7F9CF5&background=EBF4FF';
                }

                // For email, use the part before @ symbol
                if (str_contains($displayName, '@')) {
                    $displayName = explode('@', $displayName)[0];
                }

                $name = trim(collect(explode(' ', $displayName))->map(function ($segment) {
                    return mb_substr($segment, 0, 1);
                })->join(' '));

                return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&color=7F9CF5&background=EBF4FF';
            },
        );
    }

    protected function birthDateString(): Attribute
    {
        return Attribute::make(
            get: fn() => Carbon::parse($this->birth_date)->format('Y-m-d'),
        );
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: function () {
                $parts = array_filter([
                    $this->name,
                    $this->paternal_lastname,
                    $this->maternal_lastname,
                ]);

                return implode(' ', $parts);
            }
        );
    }

    protected function fullPhone(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->phone?->formatE164()
        );
    }

    protected function formattedBirthDate(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->birth_date?->isoFormat('D [de] MMM [de] YYYY'),
        );
    }

    protected function formattedGender(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->gender?->label()
        );
    }

    protected function profileIsComplete(): Attribute
    {
        return Attribute::make(
            get: fn() => !empty($this->name) &&
            !empty($this->paternal_lastname) &&
            !empty($this->maternal_lastname) &&
            !empty($this->phone) &&
            !empty($this->birth_date) &&
            !empty($this->gender)
        );
    }

    public function routeNotificationForVonage(Notification $notification): string
    {
        return $this->phone?->formatInternational();
    }

    /**
     * Cotizaciones de laboratorio del paciente
     */
    /*public function laboratoryQuotes(): HasMany
    {
        return $this->hasMany(LaboratoryQuote::class, 'patient_id');
    }*/

    /**
     * Resultados listos (cotizaciones con ready_at no nulo)
     */
    /*public function laboratoryResults(): HasMany
    {
        return $this->hasMany(LaboratoryQuote::class, 'patient_id')
            ->whereNotNull('ready_at');
    }*/

    /**
     * Resultados listos pero NO descargados aún
     */
    public function pendingLaboratoryResults(): HasMany
    {
        return $this->hasMany(LaboratoryQuote::class, 'contact_id')  // ← Cambiar a contact_id
            ->whereNotNull('ready_at')
            ->whereNull('results_downloaded_at');
    }

    /**
     * Notificaciones de laboratorio (modelo LaboratoryNotification)
     */
    public function laboratoryNotifications(): HasMany
    {
        return $this->hasMany(LaboratoryNotification::class, 'user_id')
            ->orderByDesc('created_at');
    }

    /**
     * Notificaciones de laboratorio NO leídas
     */
    public function unreadLaboratoryNotifications(): HasMany
    {
        return $this->hasMany(LaboratoryNotification::class, 'user_id')
            ->whereNull('read_at');
    }

    /**
     * Accesor: cantidad de resultados listos sin descargar
     */
    protected function pendingResultsCount(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->pendingLaboratoryResults()->count()
        );
    }

    /**
     * Accesor: cantidad de notificaciones sin leer
     */
    protected function unreadLabNotificationsCount(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->unreadLaboratoryNotifications()->count()
        );
    }

    /**
     * Accesor: ¿tiene resultados pendientes de descargar?
     */
    protected function hasPendingLabResults(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->pendingResultsCount > 0
        );
    }

    /**
     * Accesor: ¿tiene notificaciones sin leer?
     */
    protected function hasUnreadLabNotifications(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->unreadLabNotificationsCount > 0
        );
    }

    /**
     * Marcar todas las notificaciones de laboratorio como leídas
     */
    public function markLaboratoryNotificationsAsRead(): void
    {
        $this->unreadLaboratoryNotifications()->update([
            'read_at' => now(),
        ]);
    }

    /**
     * Obtener los últimos resultados listos (útil para mostrar en dashboard)
     */
    public function latestLaboratoryResults(int $limit = 5)
    {
        return $this->laboratoryResults()
            ->with('items')
            ->latest('ready_at')
            ->limit($limit)
            ->get();
    }
}
