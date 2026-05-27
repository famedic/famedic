<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante orden {{ $folio_orden }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; line-height: 1.45; margin: 0; padding: 22px; background: #ffffff; }
        p { margin: 0 0 8px; }

        .muted { color: #6b7280; }
        .small { font-size: 10px; }

        .header { width: 100%; margin-bottom: 14px; }
        .header td { vertical-align: middle; }
        .brand { font-size: 16px; font-weight: 700; color: #111827; }
        .title { font-size: 18px; font-weight: 700; color: #111827; margin-left: 10px; }
        .pill { display: inline-block; padding: 6px 10px; background: #eef2ff; color: #3730a3; border-radius: 999px; font-weight: 700; font-size: 10px; }
        .logo-img { height: 22px; width: auto; vertical-align: middle; }
        .logo-divider { display: inline-block; width: 1px; height: 18px; background: #e5e7eb; margin: 0 10px; vertical-align: middle; }

        .card { border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff; padding: 12px 14px; margin: 10px 0; }
        .card-soft { background: #f8fafc; }
        .card-title { font-size: 11px; font-weight: 800; color: #111827; text-transform: uppercase; letter-spacing: 0.03em; margin: 0 0 8px; }
        .badge { display: inline-block; padding: 3px 8px; background: #eef2ff; color: #3730a3; border-radius: 999px; font-weight: 700; font-size: 9px; }

        .grid { width: 100%; border-collapse: separate; border-spacing: 14px; margin: 0 -14px; }
        .grid td { width: 100%; vertical-align: top; }
        .grid-card { border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff; padding: 10px 12px; }

        .step { width: 100%; border-collapse: collapse; margin: 0 0 6px; }
        .step td { vertical-align: top; }
        .step-num { width: 26px; }
        .num { display: inline-block; width: 22px; height: 22px; line-height: 22px; text-align: center; border-radius: 999px; background: #111827; color: #ffffff; font-weight: 800; font-size: 10px; }
        .step-title { font-weight: 800; text-transform: uppercase; font-size: 10px; color: #111827; }
        .spacer-6 { height: 6px; }
        .spacer-10 { height: 10px; }

        .kv { width: 100%; border-collapse: collapse; }
        .kv td { padding: 3px 0; vertical-align: top; }
        .kv .k { width: 38%; font-weight: 700; color: #374151; }
        .kv .v { color: #111827; }

        .two-col { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .two-col td { width: 50%; vertical-align: top; }

        .studies { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 10px; }
        .studies th, .studies td { border: 1px solid #e5e7eb; padding: 7px; text-align: left; vertical-align: top; }
        .studies th { background: #f8fafc; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; font-size: 9px; }
        .package-includes { margin-top: 6px; padding: 6px 0 6px 10px; border-left: 3px solid #f97316; }
        .package-includes-title { font-size: 9px; font-weight: 800; color: #c2410c; text-transform: uppercase; letter-spacing: 0.03em; margin: 0 0 4px; }
        .package-includes ul { margin: 0; padding-left: 14px; font-size: 9px; color: #4b5563; line-height: 1.35; }
        .package-includes li { margin-bottom: 3px; }

        ul, ol { margin: 4px 0 8px 18px; padding: 0; }
        li { margin-bottom: 4px; }

        .footer { margin-top: 16px; padding-top: 10px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #6b7280; }
        .footer td { vertical-align: top; }
        .footer-right { text-align: right; }

        /* DomPDF helpers */
        .avoid-break { page-break-inside: avoid; }
    </style>
</head>
<body>

<table class="header">
    <tr>
        <td style="width:70%;">
            @if($famedic_logo_url)
                <img class="logo-img" src="{{ $famedic_logo_url }}" alt="Famedic">
                <span class="logo-divider"></span>
            @endif
            <span class="brand">{{ $laboratorio_marca }}</span>
            <span class="title">Orden de laboratorio</span>
        </td>
        <td style="width:30%; text-align:right;">
            <span class="pill">Orden confirmada</span>
        </td>
    </tr>
</table>

<p style="font-size:12px; font-weight:700; margin-bottom:6px;">Hola {{ $nombre_usuario }},</p>

@if ($withAppointment)
    <p class="muted">Gracias por confiar en FAMEDIC. <strong>Tu cita quedó confirmada</strong> en {{ $laboratorio_marca }}.</p>
    <p class="muted">Aquí tienes tu comprobante e instrucciones para presentarte sin contratiempos.</p>

    <div class="card avoid-break">
        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td style="width:70%;">
                    <div class="card-title">Tu identificación en sucursal <span class="badge">Muéstrala tal cual</span></div>
                    <table class="kv">
                        <tr><td class="k">Consecutivo</td><td class="v"><strong>{{ $consecutivo }}</strong></td></tr>
                        <tr><td class="k">Folio de orden</td><td class="v"><strong>{{ $folio_orden }}</strong></td></tr>
                        <tr><td class="k">Paciente</td><td class="v"><strong>{{ $nombre_paciente }}</strong></td></tr>
                        <tr><td class="k">Fecha de nacimiento</td><td class="v"><strong>{{ $fecha_nacimiento }}</strong></td></tr>
                    </table>
                </td>
                <td style="width:30%; text-align:right;">
                    @if($laboratorio_logo_url)
                        <img src="{{ $laboratorio_logo_url }}" alt="{{ $laboratorio_marca }}" style="max-height:44px; max-width:120px;">
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="card card-soft avoid-break">
        <div class="card-title">Datos de tu cita</div>
        <table class="kv">
            <tr><td class="k">Laboratorio / Marca</td><td class="v"><strong>{{ $laboratorio_marca }}</strong></td></tr>
            <tr><td class="k">Fecha de la cita</td><td class="v"><strong>{{ $appointment_date ?? '—' }}</strong></td></tr>
            <tr><td class="k">Hora de la cita</td><td class="v"><strong>{{ $appointment_time ?? '—' }}</strong></td></tr>
            <tr><td class="k">Sucursal de la cita</td><td class="v"><strong>{{ $branch_name ?? '—' }}</strong></td></tr>
            <tr><td class="k">Dirección (si aplica)</td><td class="v">{{ $branch_address ?? '—' }}</td></tr>
        </table>
        <div class="spacer-6"></div>
        <table class="two-col">
            <tr>
                <td>
                    <table class="kv">
                        <tr><td class="k">Estatus de pago</td><td class="v"><strong>{{ $estatus_pago }}</strong></td></tr>
                        <tr><td class="k">Método de pago</td><td class="v"><strong>{{ $metodo_pago }}</strong></td></tr>
                    </table>
                </td>
                <td>
                    <table class="kv">
                        <tr><td class="k">Total pagado</td><td class="v"><strong>{{ $total }}</strong></td></tr>
                        <tr><td class="k">Fecha de compra</td><td class="v"><strong>{{ $fecha_compra }}</strong></td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="card avoid-break">
        <div class="card-title">1. Antes de ir</div>
        <ul class="small muted">
            <li>Llega 10 minutos antes</li>
            <li>Lleva identificación oficial del paciente</li>
            <li>Ten a la mano tu folio y los datos del paciente (arriba)</li>
        </ul>
    </div>

    <div class="card avoid-break">
        <div class="card-title">2. Al llegar (paso a paso)</div>
        <ol class="small muted">
            <li>Comparte tus identificadores (Consecutivo, Folio y Paciente + Fecha de nacimiento)</li>
            <li>Confirma tu cita: <strong>{{ $appointment_date ?? '—' }} {{ $appointment_time ?? '' }}</strong> en <strong>{{ $laboratorio_marca }}</strong> sucursal <strong>{{ $branch_name ?? '—' }}</strong></li>
        </ol>
    </div>

    <div class="card avoid-break">
        <div class="card-title">Indicaciones de preparación (por estudio)</div>
        @if (count($studies) > 0)
            <table class="studies">
                <thead>
                    <tr>
                        <th style="width:45%;">Estudio</th>
                        <th>Preparación / Indicaciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($studies as $study)
                        <tr>
                            <td>
                                <strong>{{ $study['name'] ?? '—' }}</strong>
                                @php $pkg = $study['feature_list'] ?? []; @endphp
                                @if (is_array($pkg) && count($pkg) > 0)
                                    <div class="package-includes">
                                        <p class="package-includes-title">Incluye en este paquete</p>
                                        <ul>
                                            @foreach ($pkg as $line)
                                                <li>{{ $line }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </td>
                            <td>{!! nl2br(e($study['instructions'] ?? '—')) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="muted small">Sin estudios registrados en esta orden.</p>
        @endif
    </div>

    <div class="card avoid-break">
        <div class="card-title">¿Necesitas ayuda? Estamos contigo</div>
        <p class="muted small">Si necesitas reprogramar, si no pudiste asistir o si requieres cambios/cancelación, contáctanos y lo resolvemos contigo.</p>
        <p class="small">Atención a clientes FAMEDIC: <strong>812 860 1893</strong></p>
    </div>
@else
    <p class="muted">Gracias por confiar en FAMEDIC. Tu compra quedó confirmada en {{ $laboratorio_marca }}.</p>
    <p class="muted">Te compartimos tu comprobante e instrucciones para que puedas presentarte en sucursal con total tranquilidad.</p>

    <div class="card avoid-break">
        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td style="width:70%;">
                    <div class="card-title">Tu identificación en sucursal <span class="badge">Muéstrala tal cual</span></div>
                    <table class="kv">
                        <tr><td class="k">Consecutivo</td><td class="v"><strong>{{ $consecutivo }}</strong></td></tr>
                        <tr><td class="k">Folio de orden</td><td class="v"><strong>{{ $folio_orden }}</strong></td></tr>
                        <tr><td class="k">Paciente</td><td class="v"><strong>{{ $nombre_paciente }}</strong></td></tr>
                        <tr><td class="k">Fecha de nacimiento</td><td class="v"><strong>{{ $fecha_nacimiento }}</strong></td></tr>
                    </table>
                </td>
                <td style="width:30%; text-align:right;">
                    @if($laboratorio_logo_url)
                        <img src="{{ $laboratorio_logo_url }}" alt="{{ $laboratorio_marca }}" style="max-height:44px; max-width:120px;">
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="card avoid-break">
        <div class="card-title">Resumen de tu compra</div>
        <table class="two-col">
            <tr>
                <td>
                    <table class="kv">
                        <tr><td class="k">Laboratorio / Marca</td><td class="v">{{ $laboratorio_marca }}</td></tr>
                        <tr><td class="k">Estatus de pago</td><td class="v">{{ $estatus_pago }}</td></tr>
                        <tr><td class="k">Método de pago</td><td class="v">{{ $metodo_pago }}</td></tr>
                    </table>
                </td>
                <td>
                    <table class="kv">
                        <tr><td class="k">Total pagado</td><td class="v"><strong>{{ $total }}</strong></td></tr>
                        <tr><td class="k">Fecha de compra</td><td class="v">{{ $fecha_compra }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="card card-soft avoid-break">
        <div class="card-title">Vigencia importante (30 días)</div>
        <p class="muted">Tienes 30 días naturales a partir de tu compra para realizar tus estudios. Si no se utilizan dentro de ese periodo, tu orden podrá cancelarse.</p>
    </div>

    <table class="grid">
        <tr>
            <td>
                <div class="grid-card avoid-break">
                    <table class="step">
                        <tr>
                            <td class="step-num"><span class="num">1</span></td>
                            <td><div class="step-title">¿A dónde puedes ir?</div></td>
                        </tr>
                    </table>
                    <div class="spacer-6"></div>
                    <p class="muted small">Tus estudios NO requieren cita. Puedes acudir en cualquier momento dentro del horario de atención de la sucursal.</p>
                    <div class="spacer-6"></div>
                    <p class="muted small">Consulta sucursales, dirección, horarios y teléfono:</p>
                    <p class="small"><strong>{{ $branches_url }}</strong></p>
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="grid-card avoid-break">
                    <table class="step">
                        <tr>
                            <td class="step-num"><span class="num">2</span></td>
                            <td><div class="step-title">¿Qué llevar?</div></td>
                        </tr>
                    </table>
                    <div class="spacer-6"></div>
                    <ul class="small muted">
                        <li>Tu folio de orden (arriba) o este documento (en tu celular o impreso)</li>
                        <li>Identificación oficial del paciente</li>
                    </ul>
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="grid-card avoid-break">
                    <table class="step">
                        <tr>
                            <td class="step-num"><span class="num">3</span></td>
                            <td><div class="step-title">Al llegar a la sucursal (paso a paso)</div></td>
                        </tr>
                    </table>
                    <div class="spacer-6"></div>
                    <ol class="small muted">
                        <li>Comparte tus identificadores (Consecutivo, Folio y Paciente + Fecha de nacimiento)</li>
                        <li>Atiende las indicaciones del personal de la sucursal para realizar tus estudios</li>
                    </ol>
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="grid-card avoid-break">
                    <table class="step">
                        <tr>
                            <td class="step-num"><span class="num">4</span></td>
                            <td><div class="step-title">Indicaciones de preparación (por estudio)</div></td>
                        </tr>
                    </table>
                    <div class="spacer-6"></div>
                    <p class="muted small">Lee con atención. Estas indicaciones ayudan a que tus resultados sean correctos y a evitar reprogramaciones.</p>
                    <div class="spacer-6"></div>
                    @if (count($studies) > 0)
                        <table class="studies">
                            <thead>
                                <tr>
                                    <th style="width:45%;">Estudio</th>
                                    <th>Preparación / Indicaciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($studies as $study)
                                    <tr>
                                        <td>
                                            <strong>{{ $study['name'] ?? '—' }}</strong>
                                            @php $pkg = $study['feature_list'] ?? []; @endphp
                                            @if (is_array($pkg) && count($pkg) > 0)
                                                <div class="package-includes">
                                                    <p class="package-includes-title">Incluye en este paquete</p>
                                                    <ul>
                                                        @foreach ($pkg as $line)
                                                            <li>{{ $line }}</li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                        </td>
                                        <td>{!! nl2br(e($study['instructions'] ?? '—')) !!}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="muted small">Sin estudios registrados en esta orden.</p>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="card avoid-break" style="margin-top:10px;">
        <div class="card-title">¿Necesitas ayuda? Estamos contigo</div>
        <p class="muted small">Si no pudiste asistir, si necesitas cambios o una cancelación, contáctanos y lo resolvemos contigo.</p>
        <p class="small">Atención a clientes FAMEDIC: <strong>812 860 1893</strong></p>
    </div>
@endif

<table class="footer" width="100%">
    <tr>
        <td>
            <p style="margin:0;">Con gusto te acompañamos,</p>
            <p style="margin:0; font-weight:700;">Equipo FAMEDIC</p>
        </td>
        <td class="footer-right">
            <p style="margin:0;">Documento generado automáticamente por Famedic</p>
            <p style="margin:0;">Folio {{ $folio_orden }}</p>
        </td>
    </tr>
</table>
</body>
</html>
