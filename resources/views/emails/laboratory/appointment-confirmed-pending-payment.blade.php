<x-mail::message>

@include('emails.laboratory.components.header-logos', [
    'famedic_logo_url' => $famedic_logo_url,
    'laboratorio_logo_url' => $laboratorio_logo_url,
    'laboratorio_marca' => $laboratorio_marca,
])

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Hola <b>{{ $nombre_usuario }}</b> 👋,
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Tu cita en <b>{{ $laboratorio_marca }}</b> ya quedó <b>registrada</b> ✅
</p>

<p style="margin:0 0 20px;color:#92400e;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px 14px;font-size:15px;line-height:1.5;">
    🎉 ¡Tu cita ya está lista!
    <br><br>
    Para asegurar tu lugar en la sucursal seleccionada, realiza ahora el pago de tus estudios.
    Al finalizar, tu cita quedará confirmada y podrás obtener tu <b>orden de laboratorio</b> junto con los folios necesarios para tu atención.
</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>👤 PACIENTE</strong>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">Nombre: <b>{{ $patient_name }}</b></p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">Fecha de nacimiento: <b>{{ $patient_birth_date }}</b></p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">Sexo: <b>{{ $patient_gender }}</b></p>
<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">Teléfono: <b>{{ $patient_phone }}</b></p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>📍 DIRECCIÓN</strong>
</p>
<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">{{ $address }}</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>💳 MÉTODO DE PAGO</strong>
</p>
<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;"><b>{{ $payment_method }}</b></p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>📅 TU CITA</strong>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">Laboratorio: <b>{{ $laboratorio_marca }}</b></p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">Fecha: <b>{{ $appointment_date }}</b></p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">Hora: <b>{{ $appointment_time }}</b></p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">Sucursal: <b>{{ $branch_name }}</b></p>
<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">Dirección sucursal: {{ $branch_address }}</p>

@if ($notes)
<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>Notas:</strong> {{ $notes }}
</p>
@endif

<x-mail::button :url="$checkout_url" color="primary">
Pagar ahora
</x-mail::button>

<p style="margin:16px 0 0;color:#718096;font-size:13px;line-height:1.5;">
    Al hacer clic en <b>Pagar ahora</b>,
    accederás directamente al checkout con tu información precargada para finalizar el pago y confirmar tu cita.
</p>

<p style="margin:24px 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">Con gusto te acompañamos,</p>
<p style="margin:0;color:#3d4852;font-size:16px;line-height:1.5;">Equipo FAMEDIC 💙</p>

</x-mail::message>
