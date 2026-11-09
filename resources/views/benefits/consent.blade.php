<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Confirmar beneficio · FAMEDIC</title>
<style>body{font:17px/1.55 system-ui,sans-serif;background:#f3f6f8;color:#183b46;margin:0}main{max-width:660px;margin:5vh auto;padding:32px;background:white;border-radius:16px}h1{font-size:1.8rem}dt{color:#49656d}dd{margin:0 0 16px;overflow-wrap:anywhere}button{padding:14px 20px;border:0;border-radius:8px;background:#006a68;color:white;font:inherit;cursor:pointer}small{display:block;color:#49656d}.note{padding:16px;background:#eef6f6;border-radius:8px}code{overflow-wrap:anywhere}</style></head><body><main>
<p>FAMEDIC · Tu cuenta</p><h1>Confirmación de beneficio</h1>
@if($disabled ?? false)
<p class="note">La emisión de autorizaciones todavía no está habilitada. No se aplicó ningún beneficio ni se consumió saldo.</p>
@elseif(isset($notice))
<p class="note">{{ $notice }}</p><a href="{{ url('/confirm-password') }}">Confirmar contraseña</a>
@elseif(isset($review))
<p>Revisa esta selección del carrito médico antes de autorizarla.</p>
<dl><dt>Operación</dt><dd>{{ $review['operation']==='apply' ? 'Aplicar beneficio' : 'Retirar beneficio' }}</dd>
<dt>Beneficio</dt><dd>{{ $review['benefit_type']==='credit' ? 'Saldo a favor' : 'Cupón' }}</dd>
<dt>Carrito médico</dt><dd><code>{{ $review['cart_ref'] }}</code></dd>
<dt>Monto del beneficio</dt><dd>${{ number_format($review['amount_cents']/100,2) }} MXN</dd>
<dt>Subtotal</dt><dd>${{ number_format($review['totals']['subtotal_cents']/100,2) }} MXN</dd>
<dt>Descuento</dt><dd>${{ number_format($review['totals']['discount_cents']/100,2) }} MXN</dd>
<dt>Saldo seleccionado</dt><dd>${{ number_format($review['totals']['credit_applied_cents']/100,2) }} MXN</dd>
<dt>Total previsto</dt><dd>${{ number_format($review['totals']['total_cents']/100,2) }} MXN</dd>
<dt>Vigencia de esta revisión</dt><dd>{{ $review['expires_at'] }} (UTC)</dd></dl>
<p class="note">{{ $confirmationText ?? 'Texto propuesto pendiente de aprobación: autorizo únicamente la operación, el beneficio y los montos mostrados para esta versión del carrito. Esta confirmación no realiza un cobro ni consume definitivamente mi saldo. Un cambio en el carrito requiere una nueva revisión.' }}</p>
<small>Versión del texto: {{ $review['text_version'] }}</small>
<form method="post" action="{{ url('/benefits/consent/confirm') }}">@csrf
@foreach($form as $field=>$value)<input type="hidden" name="{{ $field }}" value="{{ $value }}">@endforeach
<button type="submit">Confirmar esta selección</button></form>
@elseif(isset($confirmation_ref))
<p>Confirmación registrada. No se realizó ningún cobro.</p><p>Vence: {{ $expires_at }} (UTC).</p>
<p><code>{{ $confirmation_ref }}</code></p>
<form method="post" action="{{ url('/benefits/consent/'.$confirmation_ref.'/revoke') }}">@csrf<button type="submit">Revocar confirmación</button></form>
@elseif($revoked ?? false)<p>Confirmación revocada. Ya no puede utilizarse para aplicar este beneficio.</p>
@endif
<small>FAMEDIC verifica tu sesión y la confirmación reciente de contraseña. Leo no puede emitir esta autorización en tu nombre.</small>
</main></body></html>