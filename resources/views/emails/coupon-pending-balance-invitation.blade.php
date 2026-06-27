<x-mail::message>
# Saldo a favor pendiente

@if($displayName)
Hola {{ $displayName }},
@else
Hola,
@endif

Tienes **{{ $formattedAmount }}** de saldo a favor pendiente en Famedic.

@if($validityStatus === 'programado' && $validFrom)
Este saldo estará disponible a partir del **{{ $validFrom->timezone(config('app.timezone'))->isoFormat('D [de] MMMM [de] YYYY') }}**.
@elseif($expiresAt)
@if($validityStatus === 'vencido')
Este saldo tenía vigencia hasta el **{{ $expiresAt->timezone(config('app.timezone'))->isoFormat('D [de] MMMM [de] YYYY') }}**.
@else
Vigencia: hasta el **{{ $expiresAt->timezone(config('app.timezone'))->isoFormat('D [de] MMMM [de] YYYY') }}**.
@endif
@endif

@if($formattedMinPurchase)
Compra mínima requerida para usarlo: **{{ $formattedMinPurchase }}**.
@endif

Crea tu cuenta usando **este mismo correo** ({{ $recipientEmail }}) para poder utilizarlo. Después de registrarte, deberás verificar tu correo para activar el saldo.

<x-mail::button :url="route('register')">
    Crear cuenta
</x-mail::button>

Si ya tienes cuenta, [inicia sesión]({{ route('login') }}) con el correo **{{ $recipientEmail }}**.

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
