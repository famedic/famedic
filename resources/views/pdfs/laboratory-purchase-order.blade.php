<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Orden de laboratorio {{ $folio_orden }}</title>
    <style>
        @page { margin: 24px 32px 28px; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: #172033;
            background: #ffffff;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10.8px;
            line-height: 1.52;
        }

        p { margin: 0; }
        ul, ol { margin: 4px 0 0 15px; padding: 0; }
        li { margin-bottom: 3px; }
        strong {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-weight: 700;
        }

        .muted { color: #4f5e73; }
        .avoid-break { page-break-inside: avoid; }

        .topbar {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }

        .topbar td { vertical-align: middle; }
        .logo-img { height: 24px; width: auto; }
        .lab-logo { max-height: 28px; max-width: 118px; }
        .brand-name { color: #172033; font-size: 12px; font-weight: 700; }

        .hero {
            margin-bottom: 16px;
            border-top: 4px solid #c8f24a;
            background: #171f45;
            color: #ffffff;
            padding: 19px 22px 20px;
        }

        .hero-table,
        .identity-table,
        .summary-table,
        .summary-row,
        .appointment-table,
        .validity-table,
        .step-table,
        .support-table {
            width: 100%;
            border-collapse: collapse;
        }

        .hero-table td,
        .identity-table td,
        .summary-table td,
        .summary-row td,
        .appointment-table td,
        .validity-table td,
        .step-table td,
        .support-table td {
            vertical-align: top;
        }

        .hero-copy { width: 66%; padding-right: 22px; }
        .hero-aside { width: 34%; text-align: right; }

        .eyebrow {
            color: #5140a0;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0;
        }

        .hero .eyebrow { color: #c8f24a; }

        .hero-title {
            margin: 3px 0 8px;
            font-size: 30px;
            line-height: 1.08;
            font-weight: 700;
        }

        .hero-text {
            color: #e4e9f5;
            font-size: 11px;
            line-height: 1.58;
        }

        .hero-total-label {
            margin-top: 31px;
            color: #c8d0e2;
            font-size: 8.7px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .hero-total {
            margin-top: 4px;
            color: #c8f24a;
            font-size: 26px;
            line-height: 1.06;
            font-weight: 700;
        }

        .confirmed {
            display: inline-block;
            color: #e8fbef;
            font-size: 8.8px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
        }

        .confirmed-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            margin-right: 5px;
            border-radius: 99px;
            background: #c8f24a;
        }

        .section {
            margin-top: 15px;
        }

        .section-heading {
            padding-top: 9px;
            border-top: 1px solid #dce3ee;
        }

        .section-title {
            margin: 0 0 8px;
            color: #172033;
            font-size: 18px;
            line-height: 1.2;
            font-weight: 700;
        }

        .section-copy {
            color: #4f5e73;
            font-size: 10.3px;
            line-height: 1.56;
        }

        .label {
            display: block;
            margin-bottom: 2px;
            color: #5e6b7f;
            font-size: 8.7px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .value {
            color: #172033;
            font-size: 11.5px;
            font-weight: 700;
            line-height: 1.34;
        }

        .identity {
            border-top: 2px solid #182033;
            border-bottom: 1px solid #dce3ee;
            padding: 13px 0;
        }

        .identity-main { width: 68%; padding-right: 18px; }
        .identity-ticket { width: 32%; }

        .identity-grid {
            width: 100%;
            border-collapse: collapse;
        }

        .identity-grid td {
            width: 50%;
            padding: 0 16px 10px 0;
        }

        .ticket {
            border-left: 4px solid #5140a0;
            background: #f7f5ff;
            padding: 11px 0 11px 13px;
        }

        .ticket-number {
            color: #231a54;
            font-size: 25px;
            line-height: 1.05;
            font-weight: 700;
        }

        .ticket-help {
            margin-top: 4px;
            color: #4f5e73;
            font-size: 9.6px;
            font-weight: 700;
        }

        .summary {
            padding-bottom: 11px;
            border-bottom: 1px solid #dce3ee;
        }

        .summary-details { width: 66%; padding-right: 22px; }
        .summary-total { width: 34%; text-align: right; }

        .summary-row {
            border-bottom: 1px solid #edf1f6;
        }

        .summary-row td {
            padding: 5px 10px 5px 0;
        }

        .summary-row td:first-child {
            width: 38%;
            color: #5e6b7f;
            font-size: 8.8px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .summary-row td:last-child {
            color: #172033;
            font-size: 11px;
            font-weight: 700;
        }

        .total-rule {
            display: inline-block;
            width: 86px;
            border-top: 3px solid #c8f24a;
            margin-bottom: 9px;
        }

        .total-label {
            color: #5e6b7f;
            font-size: 8.8px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .total-value {
            margin-top: 4px;
            color: #171f45;
            font-size: 23px;
            line-height: 1.1;
            font-weight: 700;
        }

        .credit-note {
            margin-top: 7px;
            color: #087a4c;
            font-size: 9.4px;
            font-weight: 700;
        }

        .appointment {
            padding: 12px 0 11px;
            border-top: 1px solid #dce3ee;
            border-bottom: 1px solid #dce3ee;
        }

        .appointment-date { width: 28%; padding-right: 18px; }
        .appointment-place { width: 72%; }

        .date-card {
            color: #171f45;
            font-weight: 700;
        }

        .date-main {
            font-size: 22px;
            line-height: 1.12;
        }

        .time-main {
            margin-top: 3px;
            color: #5140a0;
            font-size: 17px;
            line-height: 1.15;
        }

        .place-name {
            color: #172033;
            font-size: 13.5px;
            line-height: 1.2;
            font-weight: 700;
        }

        .place-address {
            margin-top: 4px;
            color: #4f5e73;
            font-size: 10.3px;
            line-height: 1.5;
        }

        .validity {
            margin-top: 12px;
            padding: 8px 0 8px 12px;
            border-left: 3px solid #c8f24a;
        }

        .validity-days {
            width: 95px;
            color: #171f45;
            font-size: 19px;
            line-height: 1.1;
            font-weight: 700;
        }

        .validity-copy {
            color: #4f5e73;
            font-size: 10.2px;
            line-height: 1.48;
        }

        .steps {
            margin-top: 4px;
        }

        .step {
            page-break-inside: avoid;
            padding: 7px 0 8px;
            border-bottom: 1px solid #e3e8f1;
        }

        .first-step {
            page-break-inside: avoid;
        }

        .step-number {
            width: 42px;
            color: #5140a0;
            font-size: 14.5px;
            font-weight: 700;
        }

        .step-title {
            color: #172033;
            font-size: 12px;
            line-height: 1.25;
            font-weight: 700;
        }

        .step-copy {
            margin-top: 3px;
            color: #4f5e73;
            font-size: 10.2px;
            line-height: 1.52;
        }

        .prep-intro {
            page-break-inside: avoid;
            margin-top: 14px;
            padding-top: 10px;
            border-top: 2px solid #182033;
        }

        .prep-title {
            margin-top: 2px;
            color: #182033;
            font-size: 21px;
            line-height: 1.18;
            font-weight: 700;
        }

        .study {
            page-break-inside: avoid;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #dce3ee;
        }

        .first-study {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }

        .study-heading {
            page-break-after: avoid;
            page-break-inside: avoid;
            margin-bottom: 5px;
        }

        .study-heading-table {
            width: 100%;
            border-collapse: collapse;
        }

        .study-heading-table td {
            vertical-align: top;
        }

        .study-number {
            width: 34px;
            color: #087a4c;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .study-name {
            color: #172033;
            font-size: 13.7px;
            line-height: 1.32;
            font-weight: 700;
        }

        .prep-label {
            margin: 1px 0 3px;
            color: #5140a0;
            font-size: 9.4px;
            font-weight: 700;
        }

        .instructions {
            color: #263244;
            font-size: 10.5px;
            line-height: 1.58;
        }

        .instruction-line { margin: 0 0 4px; }

        .instruction-bullet-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        .instruction-bullet-table td {
            vertical-align: top;
        }

        .bullet-mark {
            width: 13px;
            color: #5140a0;
            font-size: 10.4px;
            line-height: 1.55;
        }

        .package-includes {
            page-break-inside: avoid;
            margin: 6px 0 5px;
            padding: 5px 0 4px 9px;
            border-left: 3px solid #f59e0b;
        }

        .package-includes-title {
            margin: 0 0 3px;
            color: #9a3412;
            font-size: 8.6px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .package-includes ul {
            margin-top: 0;
            color: #7c2d12;
            font-size: 9.4px;
            line-height: 1.45;
        }

        .support {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 2px solid #5140a0;
        }

        .support-title {
            color: #172033;
            font-size: 17px;
            line-height: 1.2;
            font-weight: 700;
        }

        .support-copy {
            margin-top: 4px;
            color: #4f5e73;
            font-size: 10.2px;
            line-height: 1.5;
        }

        .support-phone {
            margin-top: 6px;
            color: #172033;
            font-size: 10.8px;
        }

        .support-table {
            margin-top: 9px;
            border-top: 1px solid #dce3ee;
        }

        .support-table td {
            width: 25%;
            padding: 7px 10px 0 0;
        }

        .support-footer {
            margin-top: 7px;
            padding-top: 6px;
            border-top: 1px solid #dce3ee;
            color: #5f6b7c;
            font-size: 8.8px;
        }

        .support-footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .support-footer-table td {
            vertical-align: top;
        }

        .support-footer-right { text-align: right; }
    </style>
</head>
<body>
@php
    $supportPhone = '812 860 1893';
    $appointmentHasDetails = $withAppointment && (($appointment_date ?? null) || ($appointment_time ?? null) || ($branch_name ?? null) || ($branch_address ?? null));
@endphp

<table class="topbar">
    <tr>
        <td style="width:50%;">
            @if($famedic_logo_url)
                <img class="logo-img" src="{{ $famedic_logo_url }}" alt="Famedic">
            @else
                <span class="brand-name">FAMEDIC</span>
            @endif
        </td>
        <td style="width:50%; text-align:right;">
            @if($laboratorio_logo_url)
                <img class="lab-logo" src="{{ $laboratorio_logo_url }}" alt="{{ $laboratorio_marca }}">
            @else
                <span class="brand-name">{{ $laboratorio_marca }}</span>
            @endif
        </td>
    </tr>
</table>

<div class="hero avoid-break">
    <table class="hero-table">
        <tr>
            <td class="hero-copy">
                <div class="eyebrow">Orden de laboratorio</div>
                <div class="hero-title">Orden confirmada</div>
                <p class="hero-text">Hola {{ $nombre_usuario }},</p>
                @if ($withAppointment)
                    <p class="hero-text" style="margin-top:5px;">Tu cita quedó confirmada en {{ $laboratorio_marca }}. Aquí tienes tu comprobante e instrucciones para presentarte sin contratiempos.</p>
                @else
                    <p class="hero-text" style="margin-top:5px;">Tu compra quedó confirmada en {{ $laboratorio_marca }}. Aquí tienes tu comprobante e instrucciones para presentarte en sucursal con total tranquilidad.</p>
                @endif
            </td>
            <td class="hero-aside">
                <span class="confirmed"><span class="confirmed-dot"></span>Confirmada</span>
                <div class="hero-total-label">Total pagado</div>
                <div class="hero-total">{{ $total }}</div>
            </td>
        </tr>
    </table>
</div>

<div class="identity avoid-break">
    <table class="identity-table">
        <tr>
            <td class="identity-main">
                <div class="section-title">Tu identificación</div>
                <table class="identity-grid">
                    <tr>
                        <td>
                            <span class="label">Consecutivo</span>
                            <span class="value">{{ $consecutivo }}</span>
                        </td>
                        <td>
                            <span class="label">Folio</span>
                            <span class="value">{{ $folio_orden }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <span class="label">Paciente</span>
                            <span class="value">{{ $nombre_paciente }}</span>
                        </td>
                        <td>
                            <span class="label">Nacimiento</span>
                            <span class="value">{{ $fecha_nacimiento }}</span>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="identity-ticket">
                <div class="ticket">
                    <span class="label">Identificador</span>
                    <div class="ticket-number">{{ $consecutivo }}</div>
                    <div class="ticket-help">Muéstralo tal cual en sucursal</div>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="section summary avoid-break">
    <table class="summary-table">
        <tr>
            <td class="summary-details">
                <div class="section-title">Resumen de compra</div>
                <table class="summary-row">
                    <tr><td>Laboratorio</td><td>{{ $laboratorio_marca }}</td></tr>
                </table>
                <table class="summary-row">
                    <tr><td>Método</td><td>{{ $metodo_pago }}</td></tr>
                </table>
                <table class="summary-row">
                    <tr><td>Fecha</td><td>{{ $fecha_compra }}</td></tr>
                </table>
                <table class="summary-row">
                    <tr><td>Estatus</td><td>{{ $estatus_pago }}</td></tr>
                </table>
                <table class="summary-row">
                    <tr><td>Subtotal</td><td>{{ $subtotal ?? $total_gross ?? $total }}</td></tr>
                </table>
                <table class="summary-row">
                    <tr><td>Descuento</td><td>{{ $catalog_discount ?? 'No aplica' }}</td></tr>
                </table>
                <table class="summary-row">
                    <tr><td>Crédito</td><td>{{ $coupon_discount ?? 'No aplica' }}</td></tr>
                </table>
                @if (!empty($credit_applied_message))
                    <p class="credit-note">{{ $credit_applied_message }}</p>
                @endif
            </td>
            <td class="summary-total">
                <span class="total-rule"></span>
                <div class="total-label">Total</div>
                <div class="total-value">{{ $total }}</div>
            </td>
        </tr>
    </table>
</div>

@if ($appointmentHasDetails)
    <div class="section appointment avoid-break">
        <div class="eyebrow">Tu cita</div>
        <table class="appointment-table">
            <tr>
                <td class="appointment-date">
                    <div class="date-card">
                        <div class="date-main">{{ $appointment_date ?? '-' }}</div>
                        <div class="time-main">{{ $appointment_time ?? '-' }}</div>
                    </div>
                </td>
                <td class="appointment-place">
                    <div class="place-name">{{ $laboratorio_marca }}{{ ($branch_name ?? null) ? ' - '.$branch_name : '' }}</div>
                    <div class="place-address">{{ $branch_address ?? '-' }}</div>
                </td>
            </tr>
        </table>
    </div>
@endif

<div class="validity avoid-break">
    <table class="validity-table">
        <tr>
            <td class="validity-days">
                <span class="label">Vigencia</span>
                30 días
            </td>
            <td class="validity-copy">
                Utiliza tu orden dentro de los 30 días naturales posteriores a tu compra. Si no se utiliza dentro de ese periodo, tu orden podrá cancelarse.
            </td>
        </tr>
    </table>
</div>

<div class="section section-heading">
    <div class="steps">
        <div class="step first-step">
            <div class="section-title">Antes de ir a la sucursal</div>
            <table class="step-table">
                <tr>
                    <td class="step-number">01</td>
                    <td>
                        <div class="step-title">A dónde puedes ir</div>
                        @if ($withAppointment)
                            <p class="step-copy">Acude a la sucursal indicada para tu cita. Si necesitas reprogramar, contacta a FAMEDIC antes de presentarte.</p>
                            @if (($branch_name ?? null) || ($branch_address ?? null))
                                <p class="step-copy"><strong>{{ $branch_name ?? $laboratorio_marca }}</strong> - {{ $branch_address ?? '-' }}</p>
                            @endif
                        @else
                            <p class="step-copy">Tus estudios no requieren cita. Puedes acudir dentro del horario de atención de la sucursal.</p>
                            <p class="step-copy">Consulta sucursales, dirección, horarios y teléfono: <strong>{{ $branches_url }}</strong></p>
                        @endif
                    </td>
                </tr>
            </table>
        </div>
        <div class="step">
            <table class="step-table">
                <tr>
                    <td class="step-number">02</td>
                    <td>
                        <div class="step-title">Qué llevar</div>
                        <ul class="step-copy">
                            <li>Tu folio de orden o este documento, ya sea en celular o impreso.</li>
                            <li>Identificación oficial del paciente.</li>
                        </ul>
                    </td>
                </tr>
            </table>
        </div>
        <div class="step">
            <table class="step-table">
                <tr>
                    <td class="step-number">03</td>
                    <td>
                        <div class="step-title">Al llegar a la sucursal</div>
                        <ol class="step-copy">
                            <li>Comparte tus identificadores: consecutivo, folio, paciente y fecha de nacimiento.</li>
                            @if ($withAppointment)
                                <li>Confirma tu cita: <strong>{{ $appointment_date ?? '-' }} {{ $appointment_time ?? '' }}</strong> en <strong>{{ $laboratorio_marca }}</strong>.</li>
                            @else
                                <li>Atiende las indicaciones del personal de la sucursal para realizar tus estudios.</li>
                            @endif
                        </ol>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</div>

@if (count($studies) > 0)
    @foreach ($studies as $study)
        @php
            $instructionText = (string) ($study['instructions'] ?? '-');
            $instructionLines = collect(preg_split('/\R/u', $instructionText) ?: [])
                ->map(fn ($line) => trim((string) $line))
                ->filter(fn ($line) => $line !== '')
                ->values();
            if ($instructionLines->isEmpty()) {
                $instructionLines = collect(['-']);
            }
            $pkg = $study['feature_list'] ?? [];
        @endphp
        <div class="study {{ $loop->first ? 'first-study' : '' }}">
            @if ($loop->first)
                <div class="prep-intro">
                    <div class="eyebrow">Preparación de estudios</div>
                    <div class="prep-title">Indicaciones de preparación</div>
                    <p class="section-copy">Lee con atención. Estas indicaciones ayudan a que tus resultados sean correctos y a evitar reprogramaciones.</p>
                </div>
            @endif

            <div class="study-heading">
                <table class="study-heading-table">
                    <tr>
                        <td class="study-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</td>
                        <td class="study-name">{{ $study['name'] ?? '-' }}</td>
                    </tr>
                </table>
            </div>

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

            <div class="prep-label">Preparación</div>
            <div class="instructions">
                @foreach ($instructionLines as $line)
                    @php
                        $isBullet = str_starts_with($line, '-') || str_starts_with($line, '•');
                        $cleanLine = $isBullet ? trim(ltrim($line, "-• \t")) : $line;
                    @endphp
                    @if ($isBullet)
                        <table class="instruction-bullet-table">
                            <tr>
                                <td class="bullet-mark">•</td>
                                <td>{{ $cleanLine }}</td>
                            </tr>
                        </table>
                    @else
                        <p class="instruction-line">{{ $cleanLine }}</p>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach
@else
    <div class="study first-study">
        <div class="prep-intro">
            <div class="eyebrow">Preparación de estudios</div>
            <div class="prep-title">Indicaciones de preparación</div>
            <p class="section-copy">Lee con atención. Estas indicaciones ayudan a que tus resultados sean correctos y a evitar reprogramaciones.</p>
        </div>
        <p class="muted">Sin estudios registrados en esta orden.</p>
    </div>
@endif

<div class="support">
    <div class="eyebrow">Necesitas ayuda</div>
    <div class="support-title">Estamos contigo durante tu proceso.</div>
    <p class="support-copy">Si no pudiste asistir, si necesitas cambios, reprogramación o cancelación, contáctanos y lo resolvemos contigo.</p>
    <p class="support-phone">Atención a clientes FAMEDIC: <strong>{{ $supportPhone }}</strong></p>

    <table class="support-table">
        <tr>
            <td>
                <span class="label">Folio</span>
                <span class="value">{{ $folio_orden }}</span>
            </td>
            <td>
                <span class="label">Paciente</span>
                <span class="value">{{ $nombre_paciente }}</span>
            </td>
            <td>
                <span class="label">Consecutivo</span>
                <span class="value">{{ $consecutivo }}</span>
            </td>
            <td>
                <span class="label">Laboratorio</span>
                <span class="value">{{ $laboratorio_marca }}</span>
            </td>
        </tr>
    </table>

    <div class="support-footer">
        <table class="support-footer-table">
            <tr>
                <td>
                    <span>FAMEDIC</span>
                    <span style="color:#172033; font-weight:700;"> · Equipo FAMEDIC</span>
                </td>
                <td class="support-footer-right">
                    Documento generado automáticamente · Folio {{ $folio_orden }}
                </td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>
