<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante orden {{ $folio_orden }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #3d4852; line-height: 1.45; margin: 0; padding: 24px; }
        h1 { font-size: 18px; color: #1e1a3d; margin: 0 0 8px; }
        h2 { font-size: 13px; color: #1e1a3d; margin: 20px 0 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
        p { margin: 0 0 8px; }
        .muted { color: #718096; font-size: 10px; }
        .rule { border-top: 1px solid #e2e8f0; margin: 14px 0; }
        .logos td { vertical-align: middle; padding: 0 12px 12px 0; }
        .logos img { max-height: 48px; max-width: 120px; }
        table.info { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.info td { padding: 4px 8px 4px 0; vertical-align: top; }
        table.info td.label { font-weight: bold; width: 38%; color: #4a5568; }
        table.studies { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.studies th, table.studies td { border: 1px solid #e2e8f0; padding: 8px; text-align: left; vertical-align: top; }
        table.studies th { background: #f7fafc; font-size: 10px; text-transform: uppercase; color: #718096; }
        .box { border: 1px solid #e2e8f0; background: #f8fafc; padding: 10px 12px; margin: 8px 0; }
        .box-blue { border-color: #bfdbfe; background: #eff6ff; }
        ul { margin: 4px 0 8px 18px; padding: 0; }
        li { margin-bottom: 4px; }
        .footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #718096; text-align: center; }
    </style>
</head>
<body>

<table class="logos" width="100%">
    <tr>
        @if($famedic_logo_url)
            <td><img src="{{ $famedic_logo_url }}" alt="Famedic"></td>
        @endif
        @if($laboratorio_logo_url)
            <td><img src="{{ $laboratorio_logo_url }}" alt="{{ $laboratorio_marca }}"><br><span class="muted">{{ $laboratorio_marca }}</span></td>
        @endif
    </tr>
</table>

<h1>Comprobante de orden de laboratorio</h1>
<p>Hola <strong>{{ $nombre_usuario }}</strong>,</p>

@if ($withAppointment)
    <p>Gracias por confiar en FAMEDIC. <strong>Tu pago fue exitoso.</strong></p>

    <div class="rule"></div>
    <h2>Datos de tu compra</h2>
    <table class="info">
        <tr><td class="label">Laboratorio / Marca</td><td>{{ $laboratorio_marca }}</td></tr>
        <tr><td class="label">Estatus de pago</td><td>{{ $estatus_pago }}</td></tr>
        <tr><td class="label">Método de pago</td><td>{{ $metodo_pago }}</td></tr>
        <tr><td class="label">Total pagado</td><td>{{ $total }}</td></tr>
        <tr><td class="label">Fecha de compra</td><td>{{ $fecha_compra }}</td></tr>
    </table>

    <div class="rule"></div>
    <div class="box box-blue">
        <p><strong>Información de tu cita</strong></p>
        <p>En breve recibirás un correo con los detalles de tu cita y las instrucciones de preparación para tus estudios.</p>
        @isset($appointment_date)
            <p><strong>Fecha:</strong> {{ $appointment_date }} · <strong>Hora:</strong> {{ $appointment_time ?? '—' }}</p>
            <p><strong>Sucursal:</strong> {{ $branch_name ?? '—' }}</p>
            <p>{{ $branch_address ?? '—' }}</p>
        @endisset
    </div>
@else
    <p>Gracias por confiar en FAMEDIC. <strong>Tu compra quedó confirmada en {{ $laboratorio_marca }}.</strong></p>
    <p>Te compartimos tu comprobante e instrucciones para presentarte en sucursal.</p>

    <div class="rule"></div>
    <h2>Identificación en sucursal</h2>
    <table class="info">
        <tr><td class="label">Consecutivo</td><td><strong>{{ $consecutivo }}</strong></td></tr>
        <tr><td class="label">Folio de orden</td><td><strong>{{ $folio_orden }}</strong></td></tr>
        <tr><td class="label">Paciente</td><td><strong>{{ $nombre_paciente }}</strong></td></tr>
        <tr><td class="label">Fecha de nacimiento</td><td><strong>{{ $fecha_nacimiento }}</strong></td></tr>
    </table>

    <div class="rule"></div>
    <h2>Resumen de tu compra</h2>
    <table class="info">
        <tr><td class="label">Laboratorio / Marca</td><td>{{ $laboratorio_marca }}</td></tr>
        <tr><td class="label">Estatus de pago</td><td>{{ $estatus_pago }}</td></tr>
        <tr><td class="label">Método de pago</td><td>{{ $metodo_pago }}</td></tr>
        <tr><td class="label">Total pagado</td><td>{{ $total }}</td></tr>
        <tr><td class="label">Fecha de compra</td><td>{{ $fecha_compra }}</td></tr>
    </table>

    <div class="rule"></div>
    <h2>Vigencia importante (30 días)</h2>
    <p>Tienes 30 días naturales a partir de tu compra para realizar tus estudios. Si no se utilizan dentro de ese periodo, tu orden podrá cancelarse.</p>

    <div class="rule"></div>
    <h2>¿A dónde puedes ir?</h2>
    <p>Tus estudios <strong>no requieren cita</strong>. Puedes acudir en cualquier momento dentro del horario de atención de la sucursal.</p>
    <p>Consulta sucursales, dirección, horarios y teléfono:</p>
    <p><strong>{{ $branches_url }}</strong></p>

    <div class="rule"></div>
    <h2>¿Qué llevar?</h2>
    <ul>
        <li>Tu folio de orden o este comprobante (celular o impreso)</li>
        <li>Identificación oficial del paciente</li>
    </ul>

    <div class="rule"></div>
    <h2>Al llegar a la sucursal</h2>
    <ol>
        <li>Comparte tus identificadores (Consecutivo, Folio, Paciente y fecha de nacimiento).</li>
        <li>Atiende las indicaciones del personal de la sucursal para realizar tus estudios.</li>
    </ol>

    @if (count($studies) > 0)
        <div class="rule"></div>
        <h2>Indicaciones de preparación (por estudio)</h2>
        <table class="studies">
            <thead>
                <tr>
                    <th style="width:35%;">Estudio</th>
                    <th>Preparación / Indicaciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($studies as $study)
                    <tr>
                        <td><strong>{{ $study['name'] ?? '—' }}</strong></td>
                        <td>{!! nl2br(e($study['instructions'] ?? '—')) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif

<div class="rule"></div>
<h2>¿Necesitas ayuda?</h2>
@if (!$withAppointment)
    <p>Si no pudiste asistir, si necesitas cambios o una cancelación, contáctanos y lo resolvemos contigo.</p>
@endif
<p>Atención a clientes FAMEDIC: <strong>812 860 1893</strong></p>
<p>Con gusto te acompañamos,<br>Equipo FAMEDIC</p>

<div class="footer">
    Documento generado por Famedic · Folio {{ $folio_orden }}
</div>
</body>
</html>
