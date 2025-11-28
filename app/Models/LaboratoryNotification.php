<?php
// app/Models/LaboratoryNotification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryNotification extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'laboratory_notifications';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    // En app/Models/LaboratoryNotification.php
    protected $fillable = [
        'user_id',
        'contact_id',
        'laboratory_quote_id',
        'laboratory_purchase_id',
        'gda_order_id',
        'gda_external_id',
        'gda_acuse',
        'notification_type',
        'status',
        'gda_status',
        'resource_type',
        'payload',
        'gda_message',
        'results_pdf_base64',
        'results_received_at',
        'read_at', // ← Agregar esta línea
    ];

    protected $casts = [
        'payload' => 'array',
        'gda_message' => 'array',
        'results_received_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'read_at' => 'datetime', // ← Agregar esta línea
    ];

    /**
     * Tipos de notificación
     */
    const TYPE_NOTIFICATION = 'notification';
    const TYPE_RESULTS = 'results';
    const TYPE_STATUS_UPDATE = 'status_update';

    /**
     * Estados internos del sistema
     */
    const STATUS_RECEIVED = 'received';
    const STATUS_PROCESSED = 'processed';
    const STATUS_ERROR = 'error';

    /**
     * Estados de GDA
     */
    const GDA_STATUS_COMPLETED = 'completed';
    const GDA_STATUS_IN_PROGRESS = 'in-progress';
    const GDA_STATUS_CANCELLED = 'cancelled';
    const GDA_STATUS_ACTIVE = 'active';

    /**
     * Resource types
     */
    const RESOURCE_SERVICE_REQUEST = 'ServiceRequest';
    const RESOURCE_SERVICE_REQUEST_COTIZACION = 'ServiceRequestCotizacion';

    /**
     * Relación con LaboratoryQuote
     */
    public function laboratoryQuote(): BelongsTo
    {
        return $this->belongsTo(LaboratoryQuote::class);
    }

    /**
     * Relación con LaboratoryPurchase
     */
    public function laboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }

    /**
     * Scopes
     */

    public function scopeType($query, string $type)
    {
        return $query->where('notification_type', $type);
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeGdaStatus($query, string $gdaStatus)
    {
        return $query->where('gda_status', $gdaStatus);
    }

    public function scopeWithResults($query)
    {
        return $query->whereNotNull('results_pdf_base64');
    }

    public function scopeWithoutResults($query)
    {
        return $query->whereNull('results_pdf_base64');
    }

    public function scopeForQuote($query, int $quoteId)
    {
        return $query->where('laboratory_quote_id', $quoteId);
    }

    public function scopeForPurchase($query, int $purchaseId)
    {
        return $query->where('laboratory_purchase_id', $purchaseId);
    }

    public function scopeRecentFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeOldestFirst($query)
    {
        return $query->orderBy('created_at', 'asc');
    }

    public function scopeByAcuse($query, string $acuse)
    {
        return $query->where('gda_acuse', $acuse);
    }

    public function scopeByGdaOrderId($query, string $gdaOrderId)
    {
        return $query->where('gda_order_id', $gdaOrderId);
    }

    /**
     * Métodos de ayuda
     */

    /**
     * Obtener la entidad relacionada (quote o purchase)
     */
    public function getRelatedEntity()
    {
        return $this->laboratoryQuote ?? $this->laboratoryPurchase;
    }

    /**
     * Obtener el tipo de entidad relacionada
     */
    public function getEntityType(): string
    {
        return $this->laboratory_quote_id ? 'quote' : 'purchase';
    }

    /**
     * Verificar si tiene resultados PDF
     */
    public function hasResults(): bool
    {
        return !empty($this->results_pdf_base64);
    }

    /**
     * Verificar si está procesada
     */
    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    /**
     * Verificar si hay error
     */
    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * Verificar si es de tipo notificación
     */
    public function isNotificationType(): bool
    {
        return $this->notification_type === self::TYPE_NOTIFICATION;
    }

    /**
     * Verificar si es de tipo resultados
     */
    public function isResultsType(): bool
    {
        return $this->notification_type === self::TYPE_RESULTS;
    }

    /**
     * Verificar si es de tipo actualización de estado
     */
    public function isStatusUpdateType(): bool
    {
        return $this->notification_type === self::TYPE_STATUS_UPDATE;
    }

    /**
     * Obtener el ID de la entidad relacionada
     */
    public function getRelatedEntityId(): ?int
    {
        return $this->laboratory_quote_id ?? $this->laboratory_purchase_id;
    }

    /**
     * Obtener información resumida para logs
     */
    public function getLogInfo(): array
    {
        return [
            'notification_id' => $this->id,
            'type' => $this->notification_type,
            'status' => $this->status,
            'gda_status' => $this->gda_status,
            'gda_acuse' => $this->gda_acuse,
            'gda_order_id' => $this->gda_order_id,
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->getRelatedEntityId(),
            'has_results' => $this->hasResults(),
        ];
    }

    /**
     * Marcar como procesada
     */
    public function markAsProcessed(): bool
    {
        return $this->update(['status' => self::STATUS_PROCESSED]);
    }

    /**
     * Marcar como error
     */
    public function markAsError(): bool
    {
        return $this->update(['status' => self::STATUS_ERROR]);
    }

    /**
     * Obtener array de tipos de notificación disponibles
     */
    public static function getNotificationTypes(): array
    {
        return [
            self::TYPE_NOTIFICATION,
            self::TYPE_RESULTS,
            self::TYPE_STATUS_UPDATE,
        ];
    }

    /**
     * Obtener array de estados disponibles
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_RECEIVED,
            self::STATUS_PROCESSED,
            self::STATUS_ERROR,
        ];
    }

    /**
     * Obtener array de estados GDA disponibles
     */
    public static function getGdaStatuses(): array
    {
        return [
            self::GDA_STATUS_COMPLETED,
            self::GDA_STATUS_IN_PROGRESS,
            self::GDA_STATUS_CANCELLED,
            self::GDA_STATUS_ACTIVE,
        ];
    }

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con Contact
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Scopes adicionales
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForContact($query, int $contactId)
    {
        return $query->where('contact_id', $contactId);
    }

    public function scopeWithUser($query)
    {
        return $query->whereNotNull('user_id');
    }

    public function scopeWithContact($query)
    {
        return $query->whereNotNull('contact_id');
    }

    /**
     * Métodos de ayuda adicionales
     */
    public function getUserName(): ?string
    {
        return $this->user?->name;
    }

    public function getContactName(): ?string
    {
        return $this->contact?->name;
    }

    public function hasUser(): bool
    {
        return !is_null($this->user_id);
    }

    public function hasContact(): bool
    {
        return !is_null($this->contact_id);
    }

    /**
     * Marcar como leída
     */
    public function markAsRead(): bool
    {
        return $this->update(['read_at' => now()]);
    }

    /**
     * Verificar si está leída
     */
    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }

    /**
     * Scope para notificaciones no leídas
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope para notificaciones leídas
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }
}