@component('mail::message')
# Actualización de autorización

**{{ $actor->full_name ?: $actor->email }}** registró una decisión sobre un crédito, cupón o código promocional.

@php
    $summary = $payload['summary'] ?? [];
    $event = $payload['event'] ?? '';
@endphp

**Tipo:** {{ $summary['type_label'] ?? '—' }}  
**Código / referencia:** {{ $summary['code'] ?? '—' }}  
**Monto:** {{ $summary['amount'] ?? '—' }}  
**Descripción:** {{ $summary['description'] ?? '—' }}

@if (! empty($summary['promo_code']))
**Código promocional:** {{ $summary['promo_code'] }}
@endif

@if (in_array($event, ['assignment_approved_partial', 'master_approved', 'assignment_approved_final'], true))
**Aprobaciones:** {{ $payload['current_approvals'] ?? 0 }}/{{ $payload['required_approvals'] ?? 0 }}
@if (($payload['remaining_approvals'] ?? 0) > 0)
· Faltan {{ $payload['remaining_approvals'] }} firma(s).
@else
· La solicitud quedó **aprobada**.
@endif
@endif

@if (! empty($payload['rejection_reason']))
**Motivo del rechazo:** {{ $payload['rejection_reason'] }}
@endif

@component('mail::button', ['url' => $payload['detail_url'] ?? route('admin.coupons.index')])
Ver detalle
@endcomponent

Gracias,<br>
{{ config('app.name') }}
@endcomponent
