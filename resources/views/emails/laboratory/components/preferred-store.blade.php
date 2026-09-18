{{-- Sucursal preferida elegida durante el checkout (opcional). --}}

@if(!empty($has_preferred_store))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:12px 0 16px;border-collapse:separate;">
<tr>
<td style="padding:14px 16px;border:1px solid #d5dde8;border-radius:8px;background:#f8fafc;">
<p style="margin:0 0 8px;color:#5140a0;font-size:14px;line-height:1.5;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;">
📍 Tu sucursal preferida
</p>

<p style="margin:0 0 6px;color:#141c2e;font-size:16px;line-height:1.45;font-weight:700;">
{{ $preferred_store_name }}
</p>

@if(!empty($preferred_store_address))
<p style="margin:0 0 6px;color:#3d4852;font-size:15px;line-height:1.55;">
{{ $preferred_store_address }}
</p>
@endif

@if(!empty($preferred_store_phone))
<p style="margin:0 0 6px;color:#3d4852;font-size:15px;line-height:1.55;">
📞 {{ $preferred_store_phone }}
</p>
@endif

@if(!empty($preferred_store_hours))
<p style="margin:0 0 8px;color:#3d4852;font-size:15px;line-height:1.55;">
🕒 {{ $preferred_store_hours }}
</p>
@endif

<p style="margin:0 0 12px;color:#718096;font-size:14px;line-height:1.55;">
Preferencia opcional. Si cambias de opinión, puedes acudir a otra sucursal compatible.
</p>

@if(!empty($preferred_store_google_maps_url))
<x-mail::button :url="$preferred_store_google_maps_url" color="primary">
Ver en Google Maps
</x-mail::button>
@endif
</td>
</tr>
</table>

<p style="margin:8px 0 0;color:#718096;font-size:13px;line-height:1.55;">
¿Prefieres otra sucursal?
<a href="{{ $branches_url }}" style="color:#5140a0;font-weight:600;text-decoration:underline;">
Ver más sucursales, horarios y teléfono
</a>
</p>
@else
<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
Consulta aquí sucursales, dirección, horarios (incluyendo domingos/horarios extraordinarios) y teléfono:
</p>

<x-mail::button :url="$branches_url" color="primary">
Consultar sucursales, horarios y teléfono
</x-mail::button>
@endif
