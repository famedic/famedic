<x-mail::message>
{{-- Contenido alineado a documento6-sin-cita (PDF) --}}

@include('emails.laboratory.components.header-logos', [
    'famedic_logo_url' => $famedic_logo_url,
    'laboratorio_logo_url' => $laboratorio_logo_url,
    'laboratorio_marca' => $laboratorio_marca,
])

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Hola <b>{{ $nombre_usuario }}</b> 👋,
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Gracias por confiar en FAMEDIC. <br> <b>Tu compra quedó confirmada en {{ $laboratorio_marca }}</b> ✅
</p>

<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
Te compartimos tu comprobante e instrucciones para que puedas presentarte en sucursal con total tranquilidad.
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
<strong>RESUMEN DE TU COMPRA</strong>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    🏥 Laboratorio / Marca: {{ $laboratorio_marca }}
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    💳Estatus de pago: {{ $estatus_pago }}
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    💳Método de pago: {{ $metodo_pago }}
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    💰Total pagado: {{ $total }}
</p>
<p style="margin:0 0 16px;color:#3d4852;font-size:16px;line-height:1.5;">
    🛒Fecha de compra: {{ $fecha_compra }}
</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>⏳ VIGENCIA IMPORTANTE (30 DÍAS)</strong>
</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
    Tienes 30 días naturales a partir de tu compra para realizar tus estudios.
</p>

<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
Si no se utilizan dentro de ese periodo, tu orden podrá cancelarse.
</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🔎 1. ¿A DÓNDE PUEDES IR?</strong>
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Tus estudios NO requieren cita. Puedes acudir en cualquier momento dentro del horario de atención de la sucursal.
</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
Consulta aquí sucursales, dirección, horarios (incluyendo domingos/horarios extraordinarios) y teléfono:
</p>

<x-mail::button :url="$branches_url" color="primary">
Consultar sucursales, horarios y teléfono
</x-mail::button>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🎒 2. ¿QUÉ LLEVAR?</strong>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
• Tu folio de orden (arriba) o este correo (en tu celular o impreso)
</p>
<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
• Identificación oficial del paciente
</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🚶‍♂️ 3. AL LLEGAR A LA SUCURSAL (PASO A PASO)</strong>
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
1. Indica que vienes por estudios adquiridos por FAMEDIC
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
2. Comparte tus identificadores (Consecutivo, Folio y Paciente + Fecha de nacimiento)
</p>

<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
3. Realiza tus estudios siguiendo las indicaciones de preparación
</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

@include('emails.laboratory.components.preparation', ['studies' => $studies, 'showIntro' => true])

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🤝 ¿NECESITAS AYUDA? ESTAMOS CONTIGO</strong>
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Si no pudiste asistir, si necesitas cambios o una cancelación, contáctanos y lo resolvemos contigo.
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
&nbsp;&nbsp;&nbsp;Atención a clientes FAMEDIC: 📞 <b>812 860 1893</b>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
Con gusto te acompañamos,
</p>

<p style="margin:0;color:#3d4852;font-size:16px;line-height:1.5;">
Equipo FAMEDIC 💙
</p>

</x-mail::message>
