{{-- Variables: $preparation (presenter array), $studies (legacy array), $showIntro (bool, opcional) --}}
@php
    $preparation = $preparation ?? null;
    $studies = is_array($preparation) ? ($preparation['studies'] ?? []) : ($studies ?? []);
    $showIntro = $showIntro ?? true;
    $aiStatus = is_array($preparation) ? ($preparation['ai_status'] ?? null) : null;
    $summary = is_array($preparation) ? ($preparation['summary'] ?? []) : [];
    $isAiReady = $aiStatus === 'AI_READY';
    $isPending = $aiStatus === 'AI_PENDING';
    $sections = array_values($summary['sections'] ?? []);
    $specialInstructions = array_values($summary['special_instructions'] ?? []);
    $individualInstructions = array_values($summary['individual_instructions'] ?? []);
@endphp

@if ($isAiReady)
<p style="margin:0 0 {{ $showIntro ? 8 : 20 }}px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🧪 Preparación para tus estudios</strong>
</p>

@if ($showIntro)
<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
Hemos simplificado las indicaciones para que sea más fácil prepararte.
</p>
@endif

@if (count($sections) > 0)
@foreach ($sections as $section)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border-collapse:separate;">
<tr>
<td style="padding:14px 16px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
<p style="margin:0 0 10px;color:#2d3748;font-size:16px;line-height:1.5;font-weight:600;">
{{ $section['title'] ?? 'Preparación' }}
</p>
<p style="margin:0;color:#3d4852;font-size:16px;line-height:1.6;white-space:pre-wrap;">{{ trim((string) ($section['content'] ?? '')) }}</p>
</td>
</tr>
</table>
@endforeach
@endif

@if (count($specialInstructions) > 0)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border-collapse:separate;">
<tr>
<td style="padding:14px 16px;border:1px solid #fcd34d;border-radius:8px;background:#fffbeb;">
<p style="margin:0 0 10px;color:#92400e;font-size:14px;line-height:1.4;text-transform:uppercase;letter-spacing:0.02em;font-weight:700;">
Importante
</p>
@foreach ($specialInstructions as $instruction)
<p style="margin:0 0 10px;color:#78350f;font-size:16px;line-height:1.6;white-space:pre-wrap;">{{ trim((string) ($instruction['content'] ?? '')) }}</p>
@endforeach
</td>
</tr>
</table>
@endif

@if (count($individualInstructions) > 0)
<p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;font-weight:600;">
Indicaciones específicas
</p>
@foreach ($individualInstructions as $instruction)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border-collapse:separate;">
<tr>
<td style="padding:14px 16px;border:1px solid #e2e8f0;border-radius:8px;background:#ffffff;">
<p style="margin:0 0 8px;color:#718096;font-size:13px;line-height:1.4;text-transform:uppercase;letter-spacing:0.02em;">
Estudio
</p>
<p style="margin:0 0 10px;color:#2d3748;font-size:16px;line-height:1.5;font-weight:600;">
{{ $instruction['study_name'] ?? '—' }}
</p>
<p style="margin:0;color:#3d4852;font-size:16px;line-height:1.6;white-space:pre-wrap;">{{ trim((string) ($instruction['content'] ?? '')) }}</p>
</td>
</tr>
</table>
@endforeach
@endif

@if (count($sections) === 0 && count($specialInstructions) === 0 && count($individualInstructions) === 0)
@include('laboratory.preparation.email-studies', ['studies' => $studies])
@endif
@else
<p style="margin:0 0 {{ $showIntro ? 8 : 20 }}px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🧪 4) INDICACIONES DE PREPARACIÓN (POR ESTUDIO)</strong>
</p>

@if ($showIntro)
<p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
Lee con atención. Estas indicaciones ayudan a que tus resultados sean correctos y a evitar reprogramaciones.
</p>
@endif

@include('laboratory.preparation.email-studies', ['studies' => $studies])

@if ($isPending)
<p style="margin:16px 0 0;color:#718096;font-size:14px;line-height:1.5;">
Estamos preparando un resumen más sencillo de estas indicaciones.
</p>
@endif
@endif
