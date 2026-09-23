<x-mail::message>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border-collapse:separate;">
<tr>
<td style="padding:18px 22px;border-radius:12px;background:#21184f;">
<p style="margin:0 0 7px;color:#c9f76f;font-size:11px;line-height:1.4;font-weight:600;text-transform:uppercase;letter-spacing:.06em;">
Resumen disponible
</p>
<h1 style="margin:0 0 10px;color:#ffffff;font-size:21px;line-height:1.3;font-weight:600;">
Indicaciones disponibles para el paciente
</h1>
<p style="margin:0;color:#e6e1ff;font-size:14px;line-height:1.55;">
Orden <span style="color:#ffffff;">{{ $displayOrderId }}</span>@if($patientName) · Paciente <span style="color:#ffffff;">{{ $patientName }}</span>@endif
</p>
</td>
</tr>
</table>

<p style="margin:0 0 14px;color:#3d4852;font-size:15px;line-height:1.6;">
@if($buyerName)
Hola {{ $buyerName }},
@else
Hola,
@endif
</p>

<p style="margin:0 0 18px;color:#3d4852;font-size:15px;line-height:1.6;">
Ya están disponibles las indicaciones de preparación para
@if($patientName)
{{ $patientName }}
@else
el paciente
@endif
de la orden de laboratorio {{ $displayOrderId }}. Preparamos un resumen más fácil de leer para que puedan llegar al laboratorio con claridad y sin vueltas.
</p>

@if ($summaryText)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border-collapse:separate;">
<tr>
<td style="padding:4px;border-radius:14px;background:#f1f7ff;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;">
<tr>
<td style="padding:15px 17px;border:1px solid #dbeafe;border-radius:11px;background:#fbfdff;">
<p style="margin:0 0 7px;color:#2563eb;font-size:11px;line-height:1.4;font-weight:600;text-transform:uppercase;letter-spacing:.06em;">
Resumen principal
</p>
<p style="margin:0 0 11px;color:#1f2937;font-size:15px;line-height:1.6;font-weight:500;">
{{ $summaryText }}
</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
<tr>
<td style="padding:9px 11px;border-radius:9px;background:#f4fce7;">
<p style="margin:0;color:#3f6212;font-size:13px;line-height:1.55;font-weight:500;">
Revisa el detalle antes de acudir y compártelo con el paciente si no es el titular de la cuenta.
</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
@endif

<table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:22px auto;border-collapse:separate;">
<tr>
<td align="center" style="border-radius:7px;background:#273142;">
<a href="{{ $orderUrl }}" style="display:inline-block;padding:10px 22px;color:#ffffff;font-size:14px;line-height:1.4;text-decoration:none;font-weight:500;border-radius:7px;">
Ver mi orden
</a>
</td>
</tr>
</table>

@if (count($sections) > 0)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 14px;border-collapse:collapse;">
<tr>
<td style="padding:0;">
<p style="margin:0;color:#1f2937;font-size:16px;line-height:1.4;font-weight:600;">
Indicaciones resumidas
</p>
</td>
</tr>
</table>

@foreach ($sections as $section)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border-collapse:separate;">
<tr>
<td width="38" valign="top" style="padding:14px 0 14px 14px;border-top:1px solid #dbeafe;border-bottom:1px solid #dbeafe;border-left:1px solid #dbeafe;border-radius:12px 0 0 12px;background:#fbfdff;">
<div style="width:30px;height:30px;border-radius:9px;background:#ecfccb;color:#166534;text-align:center;font-size:16px;line-height:30px;font-weight:500;">
✓
</div>
</td>
<td valign="top" style="padding:14px 16px 14px 10px;border-top:1px solid #dbeafe;border-right:1px solid #dbeafe;border-bottom:1px solid #dbeafe;border-radius:0 12px 12px 0;background:#fbfdff;">
<p style="margin:0 0 7px;color:#1f2937;font-size:15px;line-height:1.45;font-weight:600;">
{{ $section['title'] }}
</p>
<p style="margin:0;color:#4b5563;font-size:14px;line-height:1.6;white-space:pre-line;">{{ $section['content'] }}</p>
</td>
</tr>
</table>
@endforeach
@endif

@if (count($specialInstructions) > 0)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:22px 0 14px;border-collapse:separate;">
<tr>
<td style="padding:16px 18px;border:1px solid #fde68a;border-radius:12px;background:#fffbeb;">
<p style="margin:0 0 10px;color:#92400e;font-size:13px;line-height:1.4;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">
Importante
</p>
@foreach ($specialInstructions as $instruction)
<p style="margin:0 0 10px;color:#78350f;font-size:14px;line-height:1.6;white-space:pre-line;">{{ $instruction }}</p>
@endforeach
</td>
</tr>
</table>
@endif

@if (count($individualInstructions) > 0)
<p style="margin:24px 0 12px;color:#1f2937;font-size:16px;line-height:1.4;font-weight:600;">
Indicaciones por estudio
</p>

@foreach ($individualInstructions as $instruction)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border-collapse:separate;">
<tr>
<td style="padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb;">
<p style="margin:0 0 6px;color:#6b7280;font-size:12px;line-height:1.4;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">
{{ $instruction['study_name'] }}
</p>
<p style="margin:0;color:#4b5563;font-size:14px;line-height:1.6;white-space:pre-line;">{{ $instruction['content'] }}</p>
</td>
</tr>
</table>
@endforeach
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0 0;border-collapse:separate;">
<tr>
<td style="padding:14px 16px;border-radius:10px;background:#f8fafc;">
<p style="margin:0;color:#64748b;font-size:13px;line-height:1.6;">
También puedes consultar las indicaciones originales y el resumen completo desde el detalle de tu pedido.
</p>
</td>
</tr>
</table>

<p style="margin:24px 0 0;color:#3d4852;font-size:15px;line-height:1.6;">
Gracias por confiar en Famedic.
</p>
</x-mail::message>
