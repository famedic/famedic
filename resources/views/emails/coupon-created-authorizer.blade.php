@component('mail::message')
# Nuevo cupón creado

Se registró un nuevo cupón o crédito en Famedic **después de verificación OTP** del administrador que lo creó.

## Cupón

- **ID:** {{ $coupon->id }}
- **Código:** {{ $summary['code'] ?? '—' }}
- **Tipo:** {{ $summary['type_label'] ?? '—' }}
- **Monto por beneficiario:** {{ $summary['amount'] ?? '—' }}
- **Concepto:** {{ $summary['concept'] ?? '—' }}
- **Descripción:** {{ $summary['description'] ?? '—' }}
- **Estado:** {{ $summary['approval_status'] ?? '—' }}
- **Activo:** {{ ($summary['is_active'] ?? false) ? 'Sí' : 'No' }}
- **Vigencia:** {{ $summary['validity'] ?? '—' }}
- **Compra mínima:** {{ $summary['min_purchase'] ?? 'Sin requisito' }}
- **Máx. beneficiarios:** {{ $summary['max_beneficiaries'] ?? 'Sin límite' }}

## Creado por

- **Nombre:** {{ $summary['creator_name'] ?? '—' }}
- **Correo:** {{ $creator->email }}
- **Rol:** {{ $summary['creator_role'] ?? '—' }}
- **Fecha:** {{ $summary['created_at'] ?? '—' }}

@component('mail::button', ['url' => $detailUrl])
Ver cupón en admin
@endcomponent

Revisa la información y continúa el flujo de autorización si aplica.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
