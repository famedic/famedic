@component('mail::message')
@if (! empty($summary['is_promo_code']))
# Nuevo código promocional creado

Se registró un nuevo código promocional en Famedic **después de verificación OTP** del administrador que lo creó.

## Código promocional

- **ID cupón maestro:** {{ $coupon->id }}
- **Código promocional:** {{ $summary['promo_code'] ?? $summary['code'] ?? '—' }}
- **Tipo:** {{ $summary['type_label'] ?? '—' }}
- **Monto de descuento:** {{ $summary['amount'] ?? '—' }}
- **Descripción:** {{ $summary['description'] ?? '—' }}
- **Estado:** {{ $summary['approval_status'] ?? '—' }}
- **Activo:** {{ ($summary['is_active'] ?? false) ? 'Sí' : 'No' }}
- **Vigencia:** {{ $summary['validity'] ?? '—' }}
- **Compra mínima:** {{ $summary['min_purchase'] ?? 'Sin requisito' }}
- **Máx. usos (beneficiarios):** {{ $summary['max_beneficiaries'] ?? 'Sin límite' }}
- **Máx. por usuario:** {{ $summary['max_uses_per_user'] ?? '1' }}
@else
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
@endif

## Creado por

- **Nombre:** {{ $summary['creator_name'] ?? '—' }}
- **Correo:** {{ $creator->email }}
- **Rol:** {{ $summary['creator_role'] ?? '—' }}
- **Fecha:** {{ $summary['created_at'] ?? '—' }}

@component('mail::button', ['url' => $detailUrl])
@if (! empty($summary['is_promo_code']))
Ver código promocional en admin
@else
Ver cupón en admin
@endif
@endcomponent

Revisa la información y continúa el flujo de autorización si aplica.

Gracias,<br>
{{ config('app.name') }}
@endcomponent
