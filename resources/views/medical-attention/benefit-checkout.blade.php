<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Confirmar compra médica — FAMEDIC</title></head>
<body><main><h1>Confirmar compra médica</h1><p>Membresía médica anual. Esta confirmación autoriza la compra; aplicar un beneficio por sí solo no autoriza un cobro.</p>
<dl><dt>Carrito</dt><dd>{{ $review['cart_ref'] }}</dd><dt>Beneficio</dt><dd>{{ $review['benefit_type'] === 'credit' ? 'Saldo a favor' : 'Cupón' }}</dd>
<dt>Subtotal</dt><dd>{{ number_format($review['subtotal_cents']/100, 2) }} MXN</dd><dt>Cupón aplicado</dt><dd>{{ number_format($review['discount_cents']/100, 2) }} MXN</dd>
<dt>Saldo aplicado</dt><dd>{{ number_format($review['credit_cents']/100, 2) }} MXN</dd><dt>Total final a pagar</dt><dd>{{ number_format($review['cash_cents']/100, 2) }} MXN</dd>
<dt>Medio de pago</dt><dd>{{ $review['provider'] }}</dd><dt>Vigencia de esta confirmación</dt><dd>{{ $review['expires_at'] }}</dd></dl>
<p>{{ $review['terms'] }}</p><p>Condiciones versión {{ $review['terms_version'] }}. Un resultado incierto no es una compra rechazada ni autoriza un segundo cobro.</p>
@if (! $review['accepted'] || ! $review['purchase_ref'])
<form method="post" action="{{ route('medical.benefit-checkout.confirm') }}">@csrf
<input type="hidden" name="checkout_ref" value="{{ $review['checkout_ref'] }}">
<label><input type="checkbox" name="authorize_payment" value="yes" required> Autorizo personalmente la compra y el importe final mostrado.</label>
<button type="submit">Confirmar compra por {{ number_format($review['cash_cents']/100, 2) }} MXN</button></form>
@endif
<p><a href="{{ route('medical.benefit-checkout.status', $review['checkout_ref']) }}">Consultar estado de mi compra</a></p>
@if ($review['accepted'])
@foreach (['recover' => 'Consultar al proveedor y recuperar resultado', 'capture' => 'Confirmar pago PayPal previamente aprobado', 'cancel' => 'Cancelar únicamente si todavía no se despachó', 'reverse' => 'Solicitar reverso verificado (requiere autorización)'] as $action => $label)
<form method="post" action="{{ route('medical.benefit-checkout.action', [$review['checkout_ref'], $action]) }}">@csrf
<label><input type="checkbox" name="authorize_payment" value="yes" required> Confirmo esta acción: {{ $label }}</label><button type="submit">{{ $label }}</button></form>
@endforeach
@endif
</main></body></html>
