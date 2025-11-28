<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Propaganistas\LaravelPhone\Casts\RawPhoneNumberCast;

class LaboratoryPurchase extends Model
{
    use HasFactory, SoftDeletes;

    protected static $unguarded = true;

    protected $appends = [
        'formatted_created_at',
        'formatted_total',
        'formatted_birth_date',
        'formatted_gender',
        'formatted_results_uploaded_at',
        'formatted_commission',
        'full_name',
        'full_phone',
        'temporarly_hide_gda_order_id',
    ];

    protected function casts(): array
    {
        return [
            'brand' => LaboratoryBrand::class,
            'birth_date' => 'date',
            'gender' => Gender::class,
            'phone' => RawPhoneNumberCast::class . ':country_field',
            'temporarily_hide_gda_order_id' => 'boolean',
        ];
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        // Apply filtering based on the deleted flag:
        if (!isset($filters['deleted']) || $filters['deleted'] === '') {
            // "Todos": include both active and trashed records.
            $query->withTrashed();
        } elseif ($filters['deleted'] === 'true') {
            // "Cancelados": only trashed records.
            $query->onlyTrashed();
        }
        // "false": leave query as is (defaults to active only).

        return $query
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->orWhere('gda_order_id', 'LIKE', "%$search%")
                        ->orWhere('name', 'LIKE', "%$search%")
                        ->orWhere('paternal_lastname', 'LIKE', "%$search%")
                        ->orWhere('maternal_lastname', 'LIKE', "%$search%");

                    $query->orWhereHas('customer.user', function ($query) use ($search) {
                        $query->where('name', 'LIKE', "%$search%")
                            ->orWhere('email', 'LIKE', "%$search%")
                            ->orWhere('phone', 'LIKE', "%$search%")
                            ->orWhere('maternal_lastname', 'LIKE', "%$search%")
                            ->orWhere('paternal_lastname', 'LIKE', "%$search%");
                    });

                    $query->orWhereHas('transactions', function ($query) use ($search) {
                        $query->where('reference_id', 'LIKE', "%$search%");
                    });

                    $query->orWhereHas('laboratoryPurchaseItems', function ($query) use ($search) {
                        $query->where('name', 'LIKE', "%$search%");
                    });
                });
            })
            ->when(isset($filters['payment_method']) && $filters['payment_method'] !== '', function ($query) use ($filters) {
                $query->whereHas('transactions', function ($query) use ($filters) {
                    $query->where('payment_method', $filters['payment_method']);
                });
            })
            ->when(isset($filters['brand']) && $filters['brand'] !== '', function ($query) use ($filters) {
                $query->where('brand', $filters['brand']);
            })
            ->when($filters['start_date'] ?? null, function ($query, $startDate) {
                $query->where('created_at', '>=', Carbon::parse($startDate, 'America/Monterrey')->setTimezone('UTC'));
            })
            ->when($filters['end_date'] ?? null, function ($query, $endDate) {
                $query->where('created_at', '<=', Carbon::parse($endDate, 'America/Monterrey')->endOfDay()->setTimezone('UTC'));
            })
            ->when(isset($filters['invoice_requested']) && $filters['invoice_requested'] !== '', function ($query) use ($filters) {
                if ($filters['invoice_requested'] === 'true') {
                    $query->whereHas('invoiceRequest');
                } elseif ($filters['invoice_requested'] === 'false') {
                    $query->whereDoesntHave('invoiceRequest');
                }
            })
            ->when(isset($filters['invoice_uploaded']) && $filters['invoice_uploaded'] !== '', function ($query) use ($filters) {
                if ($filters['invoice_uploaded'] === 'true') {
                    $query->whereHas('invoice');
                } elseif ($filters['invoice_uploaded'] === 'false') {
                    $query->whereDoesntHave('invoice');
                }
            })
            ->when(isset($filters['results_uploaded']) && $filters['results_uploaded'] !== '', function ($query) use ($filters) {
                if ($filters['results_uploaded'] === 'true') {
                    $query->whereNotNull('results');
                } elseif ($filters['results_uploaded'] === 'false') {
                    $query->whereNull('results');
                }
            })
            ->when(isset($filters['dev_assistance']) && $filters['dev_assistance'] !== '', function ($query) use ($filters) {
                if ($filters['dev_assistance'] === 'with_requests') {
                    $query->whereHas('devAssistanceRequests');
                } elseif ($filters['dev_assistance'] === 'with_open_requests') {
                    $query->whereHas('devAssistanceRequests', function ($query) {
                        $query->whereNull('resolved_at');
                    });
                } elseif ($filters['dev_assistance'] === 'no_requests') {
                    $query->whereDoesntHave('devAssistanceRequests');
                }
            });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function transactions()
    {
        return $this->morphToMany(Transaction::class, 'transactionable')->withTrashed();
    }

    public function vendorPayments()
    {
        return $this->morphToMany(VendorPayment::class, 'vendor_paymentable');
    }

    public function invoice()
    {
        return $this->morphOne(Invoice::class, 'invoiceable')->withTrashed();
    }

    public function invoiceRequest()
    {
        return $this->morphOne(InvoiceRequest::class, 'invoice_requestable')->withTrashed();
    }

    public function laboratoryPurchaseItems()
    {
        return $this->hasMany(LaboratoryPurchaseItem::class)->withTrashed();
    }

    public function laboratoryAppointment()
    {
        return $this->hasOne(LaboratoryAppointment::class)->withTrashed();
    }

    public function devAssistanceRequests()
    {
        return $this->morphMany(DevAssistanceRequest::class, 'dev_assistance_requestable');
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn() => localizedDate($this->created_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->total_cents),
        );
    }

    protected function total(): Attribute
    {
        return Attribute::make(
            get: fn() => numberCents($this->total_cents)
        );
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->name . ' ' . $this->paternal_lastname . ' ' . $this->maternal_lastname
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

    protected function formattedResultsUploadedAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!$this->results) {
                    return null;
                }

                try {
                    $timestamp = Storage::lastModified($this->results);

                    return localizedDate(Carbon::createFromTimestamp($timestamp))->isoFormat('D MMM Y h:mm a');
                } catch (\Exception $e) {
                    return null;
                }
            }
        );
    }

    protected function temporarlyHideGdaOrderId(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->created_at?->lt(Carbon::parse('October 20, 2024')) ? true : false
        );
    }

    protected function formattedCommission(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->transactions->first()?->formatted_commission ?? formattedCentsPrice(0)
        );
    }

    // En App\Models\LaboratoryPurchase
    /*public function laboratoryQuote(): BelongsTo
    {
        return $this->belongsTo(LaboratoryQuote::class);
    }*/

    public function scopeFromQuote($query, $quoteId)
    {
        return $query->where('laboratory_quote_id', $quoteId);
    }

    // Agregar esta relación al modelo
    public function laboratoryNotifications()
    {
        return $this->hasMany(LaboratoryNotification::class);
    }

    // Método para obtener notificaciones de resultados
    public function resultNotifications()
    {
        return $this->laboratoryNotifications()
            ->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
            ->orderBy('created_at', 'desc');
    }

    // Método para verificar si tiene resultados
    public function hasResults(): bool
    {
        return $this->resultNotifications()->whereNotNull('results_pdf_base64')->exists();
    }

    // Método para obtener el último PDF de resultados
    public function getLatestResultsPdf()
    {
        return $this->resultNotifications()
            ->whereNotNull('results_pdf_base64')
            ->latest()
            ->first();
    }
}
