<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante de orden — {{ $folio_orden }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 14px; line-height: 1.5; color: #3d4852; margin: 0; padding: 24px; background: #fff; }
        .muted { color: #718096; }
        .rule { margin: 16px 0; color: #a0aec0; letter-spacing: 1px; font-size: 12px; }
        .btn { display: inline-block; padding: 10px 18px; background: #805ad5; color: #fff !important; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; }
        .footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #a0aec0; text-align: center; }
    </style>
</head>
<body>

@if ($withAppointment)
    @include('emails.laboratory.components.header-logos', [
        'famedic_logo_url' => $famedic_logo_url,
        'laboratorio_logo_url' => $laboratorio_logo_url,
        'laboratorio_marca' => $laboratorio_marca,
    ])

    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        Hola <b>{{ $nombre_usuario }}</b>,
    </p>

    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        Gracias por confiar en FAMEDIC.<br>
        <b>Tu pago fue exitoso</b>
    </p>

    <p class="rule">────────────────────────────────</p>

    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        <strong>DATOS DE TU COMPRA</strong>
    </p>

    <p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
        Laboratorio / Marca: <b>{{ $laboratorio_marca }}</b>
    </p>
    <p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
        Estatus de pago: <b>{{ $estatus_pago }}</b>
    </p>
    <p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
        Método de pago: <b>{{ $metodo_pago }}</b>
    </p>
    <p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
        Total pagado: <b>{{ $total }}</b>
    </p>
    <p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
        Fecha de compra: <b>{{ $fecha_compra }}</b>
    </p>

    <p class="rule">────────────────────────────────</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border-collapse:separate;">
        <tr>
            <td style="padding:14px 16px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;">
                <p style="margin:0 0 8px;color:#1e3a8a;font-size:16px;line-height:1.5;">
                    <strong>1. Información de tu cita</strong>
                </p>
                <p style="margin:0 0 4px;color:#1e3a8a;font-size:16px;line-height:1.5;">
                    En breve recibirás un correo con los detalles de tu cita y las instrucciones de preparación para tus estudios.
                </p>
            </td>
        </tr>
    </table>

    <p class="rule">────────────────────────────────</p>

    <p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
        <strong>¿NECESITAS AYUDA?</strong>
    </p>
    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        Atención a clientes FAMEDIC: <b>812 860 1893</b>
    </p>
    <p style="margin:0;color:#3d4852;font-size:16px;line-height:1.5;">
        Equipo FAMEDIC
    </p>

@else
    @include('emails.laboratory.components.header-logos', [
        'famedic_logo_url' => $famedic_logo_url,
        'laboratorio_logo_url' => $laboratorio_logo_url,
        'laboratorio_marca' => $laboratorio_marca,
    ])

    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        Hola <b>{{ $nombre_usuario }}</b>,
    </p>

    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        Gracias por confiar en FAMEDIC. <br> <b>Tu compra quedó confirmada en {{ $laboratorio_marca }}</b>
    </p>

    <p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
        Te compartimos tu comprobante e instrucciones para que puedas presentarte en sucursal con total tranquilidad.
    </p>

    <p class="rule">────────────────────────────────</p>

    @include('emails.laboratory.components.identification', [
        'consecutivo' => $consecutivo,
        'folio_orden' => $folio_orden,
        'nombre_paciente' => $nombre_paciente,
        'fecha_nacimiento' => $fecha_nacimiento,
    ])

    <p class="rule">────────────────────────────────</p>

    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        <strong>RESUMEN DE TU COMPRA</strong>
    </p>

    <p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
        Laboratorio / Marca: {{ $laboratorio_marca }}
    </p>
    <p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
        Estatus de pago: {{ $estatus_pago }}
    </p>
    <p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
        Método de pago: {{ $metodo_pago }}
    </p>
    <p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
        Total pagado: {{ $total }}
    </p>
    <p style="margin:0 0 16px;color:#3d4852;font-size:16px;line-height:1.5;">
        Fecha de compra: {{ $fecha_compra }}
    </p>

    <p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
        <strong>VIGENCIA IMPORTANTE (30 DÍAS)</strong>
    </p>
    <p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
        Tienes 30 días naturales a partir de tu compra para realizar tus estudios.
    </p>
    <p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
        Si no se utilizan dentro de ese periodo, tu orden podrá cancelarse.
    </p>

    <p class="rule">────────────────────────────────</p>

    <p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
        <strong>1. ¿A DÓNDE PUEDES IR?</strong>
    </p>
    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        Tus estudios NO requieren cita. Puedes acudir en cualquier momento dentro del horario de atención de la sucursal.
    </p>
    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        Consulta sucursales, dirección, horarios y teléfono:
    </p>
    <p style="margin:0 0 20px;">
        <a href="{{ $branches_url }}" class="btn">Consultar sucursales, horarios y teléfono</a>
    </p>
    <p class="muted" style="margin:0 0 20px;font-size:12px;word-break:break-all;">{{ $branches_url }}</p>

    <p class="rule">────────────────────────────────</p>

    <p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
        <strong>2. ¿QUÉ LLEVAR?</strong>
    </p>
    <p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
        • Tu folio de orden (arriba) o este documento (en tu celular o impreso)
    </p>
    <p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
        • Identificación oficial del paciente
    </p>

    <p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
        <strong>3. AL LLEGAR A LA SUCURSAL (PASO A PASO)</strong>
    </p>
    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        1. Comparte tus identificadores (Consecutivo, Folio y Paciente + Fecha de nacimiento)
    </p>
    <p style="margin:0 0 20px;color:#3d4852;font-size:16px;line-height:1.5;">
        2. Atiende las indicaciones del personal de la sucursal para realizar tus estudios
    </p>

    <p class="rule">────────────────────────────────</p>

    @include('emails.laboratory.components.preparation', ['studies' => $studies, 'showIntro' => true])

    <p class="rule">────────────────────────────────</p>

    <p style="margin:0 0 8px;color:#3d4852;font-size:16px;line-height:1.5;">
        <strong>¿NECESITAS AYUDA? ESTAMOS CONTIGO</strong>
    </p>
    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        Si no pudiste asistir, si necesitas cambios o una cancelación, contáctanos y lo resolvemos contigo.
    </p>
    <p style="margin:0 0 12px;color:#3d4852;font-size:16px;line-height:1.5;">
        Atención a clientes FAMEDIC: <b>812 860 1893</b>
    </p>
    <p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
        Con gusto te acompañamos,
    </p>
    <p style="margin:0;color:#3d4852;font-size:16px;line-height:1.5;">
        Equipo FAMEDIC
    </p>
@endif

<div class="footer">
    <p>Documento generado automáticamente por Famedic · Folio {{ $folio_orden }}</p>
</div>
</body>
</html>
