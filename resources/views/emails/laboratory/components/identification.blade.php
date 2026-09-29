{{-- Variables: $consecutivo, $folio_orden, $nombre_paciente, $fecha_nacimiento, $genero_paciente, $telefono_paciente --}}

<p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
<strong>🪪 DATOS DEL PACIENTE Y ORDEN</strong>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    🔹 Consecutivo: <strong>{{ $consecutivo }}</strong>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    🔹 Folio de orden: <strong>{{ $folio_orden }}</strong>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    🔹 Paciente:
<strong>{{ $nombre_paciente }}</strong>
</p>
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    🔹 Fecha de nacimiento:
<strong>{{ $fecha_nacimiento }}</strong>
</p>
@if (! empty($genero_paciente))
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    🔹 Sexo:
<strong>{{ $genero_paciente }}</strong>
</p>
@endif
@if (! empty($telefono_paciente))
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    🔹 Teléfono:
<strong>{{ $telefono_paciente }}</strong>
</p>
@endif
