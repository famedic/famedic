{{-- Variables: $studies (array of name/instructions), $showIntro (bool, opcional) --}}
@php
    $studies = $studies ?? [];
    $showIntro = $showIntro ?? true;
@endphp

<p style="margin:0 0 {{ $showIntro ? 8 : 20 }}px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🧪 4) INDICACIONES DE PREPARACIÓN (POR ESTUDIO)</strong>
</p>

@if ($showIntro)
<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
Lee con atención. Estas indicaciones ayudan a que tus resultados sean correctos y a evitar reprogramaciones.
</p>
@endif

@if (count($studies) > 0)
@foreach ($studies as $study)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border-collapse:separate;">
<tr>
<td style="padding:14px 16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
<p style="margin:0 0 6px;color:#718096;font-size:13px;line-height:1.4;text-transform:uppercase;letter-spacing:0.02em;">
    🔬 Estudio
</p>
<p style="margin:0 0 14px;color:#2d3748;font-size:16px;line-height:1.5;font-weight:600;">
{{ $study['name'] ?? '—' }}
</p>
<p style="margin:0 0 6px;color:#718096;font-size:13px;line-height:1.4;text-transform:uppercase;letter-spacing:0.02em;">
Preparación / Indicaciones
</p>
<p style="margin:0;color:#3d4852;font-size:16px;line-height:1.6;white-space:pre-wrap;">
 {{ $study['instructions'] ?? '—' }}
</p>
</td>
</tr>
</table>
@endforeach
@else
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0;border-collapse:separate;">
<tr>
<td style="padding:14px 16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
<p style="margin:0;color:#718096;font-size:15px;line-height:1.5;">
Sin estudios registrados en esta orden.
</p>
</td>
</tr>
</table>
@endif
