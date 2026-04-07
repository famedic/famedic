<x-mail::message>
{{-- Confirmación de compra · estudios de laboratorio --}}

# Gracias por tu compra

Hemos registrado correctamente tu pedido de estudios de laboratorio a través de **Famedic**. A continuación encontrarás un **resumen de tu compra** y las **indicaciones** para presentarte en sucursal.

<x-mail::panel>
**Folio de orden:** {{ $purchase->gda_order_id }}<br>
**Paciente:** {{ $purchase->full_name }}<br>
**Fecha:** {{ $purchase->formatted_created_at }}<br>
**Total:** {{ $purchase->formatted_total }}
</x-mail::panel>

## Detalle de estudios

<x-mail::table>
| Estudio | Precio |
| :--- | ---: |
@foreach ($studyLines as $row)
| {{ $row['name'] }} | {{ $row['price'] }} |
@endforeach
</x-mail::table>

## Método de pago

<p style="margin:12px 0 0;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>Método de pago:</strong> {{ $payment['label'] }}
@if (!empty($payment['last_four']))
· terminación <strong>****{{ $payment['last_four'] }}</strong>
@endif
</p>
<p style="margin:8px 0 0;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>Estado del pago:</strong> {{ $payment['status_text'] }}
</p>

@if ($laboratory)
## Laboratorio

<div style="text-align:center;margin:16px 0 8px;">
<img src="{{ $laboratory->logo_url }}" alt="{{ $laboratory->name }}" width="140" style="max-width:140px;height:auto;border:0;display:inline-block;">
</div>

<p style="text-align:center;margin:0 0 16px;color:#718096;font-size:14px;">
<strong>{{ $laboratory->name }}</strong>
</p>
@endif

## Sucursales

Consulta la **sucursal más cercana** y horarios en el siguiente enlace:

<x-mail::button :url="$storesUrl" color="secondary">
Ver sucursales
</x-mail::button>

@if ($instructionSectionVisible)
## 🧪 Instrucciones importantes

<p style="margin:0 0 12px;color:#718096;font-size:14px;line-height:1.5;">
Lee con atención las indicaciones de <strong>cada estudio</strong>. Si una misma indicación aplica a varios estudios, aparece agrupada al inicio para evitar confusiones.
</p>

@if (!empty($instructionSharedGroups))
<p style="margin:16px 0 8px;color:#3d4852;font-size:15px;"><strong>Indicaciones comunes a varios estudios</strong></p>

@foreach ($instructionSharedGroups as $group)
<div style="margin:0 0 20px;padding:12px 14px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;">
<p style="margin:0 0 10px;color:#3d4852;font-size:15px;line-height:1.5;">{{ $group['instruction'] }}</p>
<p style="margin:0 0 6px;font-size:13px;color:#718096;"><strong>Estudios a los que aplica esta indicación:</strong></p>
<ul style="margin:0;padding-left:20px;color:#3d4852;font-size:15px;line-height:1.6;">
@foreach ($group['study_names'] as $studyName)
<li>{{ $studyName }}</li>
@endforeach
</ul>
</div>
@endforeach
@endif

@if (!empty($instructionByStudy))
<p style="margin:20px 0 8px;color:#3d4852;font-size:15px;"><strong>Por estudio</strong></p>

@foreach ($instructionByStudy as $block)
<div style="margin:0 0 18px;@unless($loop->last)padding-bottom:14px;border-bottom:1px solid #edf2f7;@endunless">
<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;"><strong>Estudio:</strong> {{ $block['study_name'] }}</p>
<ul style="margin:0;padding-left:20px;color:#3d4852;font-size:15px;line-height:1.6;">
@foreach ($block['lines'] as $line)
<li>{{ $line }}</li>
@endforeach
</ul>
</div>
@endforeach
@endif

@endif

## Indicaciones generales

@foreach ($generalGuidelines as $line)
- {{ $line }}
@endforeach

<x-mail::button :url="$orderUrl">
Ver mi orden y comprobante
</x-mail::button>

Recibirás tus resultados en la plataforma cuando el laboratorio los tenga disponibles.

<x-mail::subcopy>
Si no ves los botones correctamente, copia y pega este enlace en tu navegador:<br>
<span class="break-all">{{ $orderUrl }}</span>
</x-mail::subcopy>

</x-mail::message>
