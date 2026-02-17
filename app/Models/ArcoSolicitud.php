<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArcoSolicitud extends Model
{
    use HasFactory;

    protected $table = 'arco_solicitudes';

    protected $fillable = [
        'user_id',
        'folio',
        'nombre_completo',
        'fecha_nacimiento',
        'rfc',
        'calle',
        'numero_exterior',
        'numero_interior',
        'colonia',
        'municipio_estado',
        'codigo_postal',
        'telefono_fijo',
        'telefono_celular',
        'derecho_acceso',
        'derecho_rectificacion',
        'derecho_cancelacion',
        'derecho_oposicion',
        'derecho_revocacion',
        'razon_solicitud',
        'solicitado_por',
        'documento_identificacion_path',
        'documento_representacion_path',
        'estado',
        'respuesta',
        'fecha_respuesta',
        'numero_oficio',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'fecha_respuesta' => 'date',
        'derecho_acceso' => 'boolean',
        'derecho_rectificacion' => 'boolean',
        'derecho_cancelacion' => 'boolean',
        'derecho_oposicion' => 'boolean',
        'derecho_revocacion' => 'boolean',
    ];

    // Relación con el usuario
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scope para filtrar por estado
    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopeAtendidas($query)
    {
        return $query->where('estado', 'atendida');
    }

    public function scopeEnProceso($query)
    {
        return $query->where('estado', 'en_proceso');
    }

    // Generar folio único
    public static function generarFolio()
    {
        $year = date('Y');
        $month = date('m');
        $random = strtoupper(substr(md5(uniqid()), 0, 6));
        
        return "ARCO-{$year}{$month}-{$random}";
    }

    // Verificar si al menos un derecho fue seleccionado
    public function tieneDerechosSeleccionados()
    {
        return $this->derecho_acceso || 
               $this->derecho_rectificacion || 
               $this->derecho_cancelacion || 
               $this->derecho_oposicion || 
               $this->derecho_revocacion;
    }

    // Obtener derechos seleccionados como texto
    public function getDerechosSeleccionadosAttribute()
    {
        $derechos = [];
        
        if ($this->derecho_acceso) $derechos[] = 'Acceso';
        if ($this->derecho_rectificacion) $derechos[] = 'Rectificación';
        if ($this->derecho_cancelacion) $derechos[] = 'Cancelación';
        if ($this->derecho_oposicion) $derechos[] = 'Oposición';
        if ($this->derecho_revocacion) $derechos[] = 'Revocación';
        
        return implode(', ', $derechos);
    }

    // Obtener estado con colores
    public function getEstadoBadgeAttribute()
    {
        $estados = [
            'pendiente' => '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">Pendiente</span>',
            'en_proceso' => '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">En Proceso</span>',
            'atendida' => '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Atendida</span>',
            'rechazada' => '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Rechazada</span>',
        ];
        
        return $estados[$this->estado] ?? $estados['pendiente'];
    }

    // Verificar si requiere documento de representación
    public function requiereDocumentoRepresentacion()
    {
        return $this->solicitado_por === 'representante';
    }
}