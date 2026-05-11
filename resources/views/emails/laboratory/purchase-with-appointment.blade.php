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
Gracias por confiar en FAMEDIC.<br> 
<b>Tu pago fue exitoso</b> ✅
</p>

<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>💰 DATOS DE TU COMPRA</strong>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    🏥 Laboratorio / Marca: <b>{{ $laboratorio_marca }}</b>
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

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border-collapse:separate;">
<tr>
<td style="padding:14px 16px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;">
<p style="margin:0 0 8px;color:#1e3a8a;font-size:16px;line-height:1.5;">
<strong>🏥 1. Información de tu cita</strong>
</p>

<p style="margin:0 0 4px;color:#1e3a8a;font-size:16px;line-height:1.5;">
    📩 En  breve recibirás un correo con los detalles de tu cita y las instrucciones de preparación para tus estudios
</p>

</td>
</tr>
</table>


<p style="margin:16px 0;color:#a0aec0;letter-spacing:1px;font-size:12px;line-height:1;">────────────────────────────────</p>

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🤝 6. ¿NECESITAS AYUDA? </strong>
</p>

<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
Atención a clientes FAMEDIC: 📞 <b>812 860 1893</b>
</p>

<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
Con gusto te apoyamos,
</p>

<p style="margin:0;color:#3d4852;font-size:16px;line-height:1.5;">
Equipo FAMEDIC 💙
</p>

</x-mail::message>
