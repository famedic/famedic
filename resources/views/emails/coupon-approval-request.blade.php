<x-mail::message>
# Solicitud de aprobación de cupones

Se requiere tu aprobación para ejecutar un cambio en el módulo de cupones.

**Solicitud:** #{{ $approvalRequest->id }}  
**Tipo:** {{ $approvalRequest->type }}  
**Estado:** {{ $approvalRequest->status }}  
**Aprobaciones requeridas:** {{ $approvalRequest->required_approvals }}

@if($approvalRequest->before_state)
## Valores anteriores

`{{ json_encode($approvalRequest->before_state, JSON_UNESCAPED_UNICODE) }}`
@endif

@if($approvalRequest->after_state)
## Valores nuevos

`{{ json_encode($approvalRequest->after_state, JSON_UNESCAPED_UNICODE) }}`
@endif

<x-mail::button :url="$approvalUrl">
Revisar solicitud en plataforma
</x-mail::button>

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
