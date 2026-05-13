<x-mail::message>
# Saldo a favor

Hola {{ $user->name }},

Se ha agregado **{{ $formattedAmount }}** a tu cuenta como saldo a favor. Podrás aplicarlo en el checkout de laboratorio cuando el total de la compra sea igual o mayor a ese monto (se usa el saldo completo en una sola compra).

<x-mail::button :url="route('laboratory-brand-selection')">
    Ir a laboratorios
</x-mail::button>

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
