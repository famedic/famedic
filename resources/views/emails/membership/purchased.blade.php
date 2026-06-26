<x-mail::message>
@if(!empty($famedic_logo_url))
<p style="margin:0 0 20px;text-align:center;">
    <img src="{{ $famedic_logo_url }}" alt="Famedic" style="max-height:48px;width:auto;">
</p>
@endif

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Hola <b>{{ $nombre_usuario }}</b> 👋,
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Tu <b>{{ $plan_name }}</b> ya está activa. A partir de ahora puedes usar tu línea de atención médica y tu número de identificación para recibir beneficios.
</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>RESUMEN DE TU COMPRA</strong>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">📦 Origen: {{ $purchase_source_label }}</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">🏷️ Plan: {{ $plan_type_label }}</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">💰 Monto: {{ $formatted_price }}</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">💳 Método de pago: {{ $payment_method }}</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">✅ Estatus: {{ $payment_status }}</p>
@if(!empty($transaction_number))
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">🧾 Transacción: {{ $transaction_number }}</p>
@endif
<p style="margin:0 0 16px;color:#3d4852;font-size:16px;line-height:1.5;">🛒 Fecha de compra: {{ $formatted_purchase_date }}</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>TU NÚMERO DE IDENTIFICACIÓN</strong>
</p>

<p style="margin:0 0 8px;color:#1a202c;font-size:32px;font-weight:700;letter-spacing:1px;line-height:1.2;">
{{ $identifier }}
</p>

<p style="margin:0 0 16px;color:#3d4852;font-size:15px;line-height:1.5;">
Tenlo a la mano al contactar la línea de atención médica.
</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>{{ strtoupper($line_label) }}</strong>
</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:24px;font-weight:700;line-height:1.3;">
<a href="{{ $tel_href }}" style="color:#2b6cb0;text-decoration:none;">{{ $formatted_phone }}</a>
</p>

<p style="margin:0 0 16px;color:#3d4852;font-size:15px;line-height:1.5;">
Marca o presiona el número para iniciar una conversación con un doctor.
</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>VIGENCIA</strong>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">📅 Inicio: {{ $formatted_start_date }}</p>
<p style="margin:0 0 16px;color:#3d4852;font-size:16px;line-height:1.5;">📅 Vence: {{ $formatted_end_date }}</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>BENEFICIOS INCLUIDOS</strong>
</p>

@foreach($benefits as $benefit)
<p style="margin:0 0 10px;color:#3d4852;font-size:15px;line-height:1.5;">
    ✅ <b>{{ $benefit['title'] }}</b><br>
    <span style="color:#718096;">{{ $benefit['description'] }}</span>
</p>
@endforeach

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<x-mail::button :url="$medical_attention_url" color="primary">
Ir a Atención médica
</x-mail::button>

<x-mail::button :url="$membership_url" color="success">
Ver Mi Membresía
</x-mail::button>

<p style="margin:16px 0 0;color:#3d4852;font-size:15px;line-height:1.5;">
También puedes registrar familiares cubiertos desde tu cuenta:
</p>

<x-mail::button :url="$family_url">
Gestionar familia
</x-mail::button>

Gracias por confiar en Famedic,<br>
{{ config('app.name') }}
</x-mail::message>
