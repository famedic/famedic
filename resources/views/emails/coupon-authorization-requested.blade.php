<x-mail::message>
# Solicitud de autorización de cupón

Se creó un cupón maestro que requiere tu visto bueno.

**Monto por beneficiario:** {{ $formattedAmount }}

@if($coupon->description)
**Descripción:** {{ $coupon->description }}
@endif

@if($coupon->max_beneficiaries)
**Máximo de beneficiarios:** {{ $coupon->max_beneficiaries }}
@endif

**Código de autorización (ingrésalo en el panel admin):** `{{ $plainCode }}`

**Vigencia:** este código expira en **5 minutos**.

Cupón ID interno: #{{ $coupon->id }}

<x-mail::button :url="route('admin.coupons.index', absolute: true)">
Ir a cupones
</x-mail::button>

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
