<x-mail::message>
{{-- Contenido alineado a documento7-con-cita (PDF) --}}

@include('emails.laboratory.components.header-logos', [
    'famedic_logo_url' => $famedic_logo_url,
    'laboratorio_logo_url' => $laboratorio_logo_url,
    'laboratorio_marca' => $laboratorio_marca,
])

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Hola <b>{{ $nombre_usuario }}</b> 👋,
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Gracias por confiar en FAMEDIC.<br> <b>Tu cita quedó confirmada en {{ $laboratorio_marca }} sucursal  {{ $branch_name }}</b>✅
</p>

<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
Aquí tienes tu comprobante e instrucciones para presentarte sin contratiempos.
</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

@include('emails.laboratory.components.identification', [
    'consecutivo' => $consecutivo,
    'folio_orden' => $folio_orden,
    'nombre_paciente' => $nombre_paciente,
    'fecha_nacimiento' => $fecha_nacimiento,
])

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>📅 DATOS DE TU CITA</strong>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    🏥 Laboratorio / Marca: <b>{{ $laboratorio_marca }}</b>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    📆 Fecha de la cita: <b>{{ $appointment_date }}</b>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    ⏰ Hora de la cita: <b>{{ $appointment_time }}</b>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    📍 Sucursal de la cita: <b>{{ $branch_name }}</b>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    📌 Dirección (si aplica): {{ $branch_address }}
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    💳 Estatus de pago: <b>{{ $estatus_pago }}</b>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    💳 Método de pago: <b>{{ $metodo_pago }}</b>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    💰 Total pagado: <b>{{ $total }}</b>
</p>
<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
    🛒 Fecha de compra: <b>{{ $fecha_compra }}</b>
</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🧠 1. ANTES DE IR</strong>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
• Llega 10 minutos antes
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
• Lleva identificación oficial del paciente
</p>
<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
• Ten a la mano tu folio y los datos del paciente (arriba)
</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🚶‍♂️ 2. AL LLEGAR (PASO A PASO)</strong>
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
1. Comparte tus identificadores (Consecutivo, Folio y Paciente + Fecha de nacimiento)
</p>

<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
2. Confirma tu cita: <b>{{ $appointment_date }} {{ $appointment_time }} en {{ $laboratorio_marca }} sucursal {{ $branch_name }}</b>
</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

@include('emails.laboratory.components.preparation', ['studies' => $studies, 'showIntro' => false])

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🤝 6. ¿NECESITAS AYUDA? ESTAMOS CONTIGO</strong>
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Si necesitas reprogramar, si no pudiste asistir o si requieres cambios/cancelación, contáctanos y lo resolvemos contigo.
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
&nbsp;&nbsp;&nbsp;  Atención a clientes FAMEDIC: 📞 <b>812 860 1893</b>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
Con gusto te acompañamos,
</p>

<p style="margin:0;color:#3d4852;font-size:16px;line-height:1.5;">
Equipo FAMEDIC 💙
</p>

</x-mail::message>
