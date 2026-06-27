<x-mail::message>
# Saldo a favor disponible

Hola {{ $user->name }},

Tu saldo a favor ya está disponible. Tienes **{{ $formattedAmount }}** listos para usar en laboratorio.

@if($validityStatus === 'programado' && $validFrom)
Podrás usarlo a partir del **{{ $validFrom->timezone(config('app.timezone'))->isoFormat('D [de] MMMM [de] YYYY') }}**.
@elseif($expiresAt)
@if($validityStatus === 'vencido')
Nota: este saldo venció el **{{ $expiresAt->timezone(config('app.timezone'))->isoFormat('D [de] MMMM [de] YYYY') }}** y no podrá aplicarse en checkout.
@else
Vigencia: hasta el **{{ $expiresAt->timezone(config('app.timezone'))->isoFormat('D [de] MMMM [de] YYYY') }}**.
@endif
@endif

@if($formattedMinPurchase)
Compra mínima requerida: **{{ $formattedMinPurchase }}**.
@endif

<x-mail::button :url="route('laboratory-brand-selection')">
    Ir a laboratorios
</x-mail::button>

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
