<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Orden de laboratorio {{ $folio_orden }}</title>
    <style>
        /*
         * Tipografía: DejaVu Sans (sans-serif nativa de DomPDF).
         * Inter/Manrope no están registradas en el stack; DejaVu Sans ofrece
         * renderizado confiable, pesos regular/bold y excelente legibilidad en PDF.
         */
        @page { margin: 26px 34px 30px; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: #141c2e;
            background: #ffffff;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11.5px;
            font-weight: 400;
            line-height: 1.58;
        }

        p { margin: 0; }
        ul, ol { margin: 5px 0 0 16px; padding: 0; }
        li { margin-bottom: 4px; }
        strong {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-weight: 700;
        }

        .muted { color: #3a4659; }
        .avoid-break { page-break-inside: avoid; }

        .topbar {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        .topbar td { vertical-align: middle; }
        .logo-img { height: 28px; width: auto; display: block; }
        .lab-logo { max-height: 30px; max-width: 120px; }
        .brand-name { color: #141c2e; font-size: 12px; font-weight: 700; }
        .brand-lockup-table { border-collapse: collapse; }
        .brand-lockup-table td { vertical-align: middle; }
        .brand-lockup-icon { padding-right: 8px; }
        .brand-wordmark {
            color: #141c2e;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
            line-height: 1;
            text-transform: lowercase;
        }

        .hero {
            margin-bottom: 20px;
            border-top: 4px solid #c8f24a;
            background: #171f45;
            color: #ffffff;
            padding: 22px 24px 22px;
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

        .hero-copy { width: 62%; padding-right: 24px; }
        .hero-aside { width: 38%; text-align: right; vertical-align: bottom; }

        .eyebrow {
            color: #5140a0;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }

        .hero .eyebrow {
            color: #c8f24a;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0;
        }

        .hero-title {
            margin: 5px 0 10px;
            font-size: 28px;
            line-height: 1.12;
            font-weight: 700;
        }

        .hero-text {
            color: #e8edf7;
            font-size: 12px;
            line-height: 1.62;
            font-weight: 400;
        }

        .hero-total-label {
            margin-top: 28px;
            color: #b8c4da;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .hero-total {
            margin-top: 5px;
            color: #c8f24a;
            font-size: 32px;
            line-height: 1.04;
            font-weight: 700;
        }

        .confirmed {
            display: inline-block;
            color: #e8fbef;
            font-size: 10.5px;
            font-weight: 700;
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
            margin-top: 20px;
        }

        .section-heading {
            padding-top: 10px;
            border-top: 1px solid #d5dde8;
        }

        .section-title {
            margin: 0 0 10px;
            color: #141c2e;
            font-size: 19px;
            line-height: 1.22;
            font-weight: 700;
        }

        .section-copy {
            color: #3a4659;
            font-size: 11.5px;
            line-height: 1.62;
            font-weight: 400;
        }

        .label {
            display: block;
            margin-bottom: 3px;
            color: #4a5568;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .value {
            color: #141c2e;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.36;
        }

        .identity {
            border-top: 2px solid #141c2e;
            border-bottom: 1px solid #d5dde8;
            padding: 16px 0 15px;
        }

        .identity-main { width: 64%; padding-right: 20px; }
        .identity-ticket { width: 36%; }

        .identity-grid {
            width: 100%;
            border-collapse: collapse;
        }

        .identity-grid td {
            width: 50%;
            padding: 0 18px 12px 0;
        }

        .ticket {
            border-left: 4px solid #5140a0;
            background: #f7f5ff;
            padding: 14px 0 14px 14px;
        }

        .ticket .label {
            color: #5140a0;
            font-size: 9.5px;
        }

        .ticket-number {
            color: #1a1240;
            font-size: 30px;
            line-height: 1.05;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        .ticket-help {
            margin-top: 5px;
            color: #3a4659;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.4;
        }

        .summary {
            padding-bottom: 12px;
            border-bottom: 1px solid #d5dde8;
        }

        .summary-details { width: 64%; padding-right: 24px; }
        .summary-total { width: 36%; text-align: right; vertical-align: bottom; }

        .summary-row {
            border-bottom: 1px solid #e8edf4;
        }

        .summary-row td {
            padding: 6px 10px 6px 0;
        }

        .summary-row td:first-child {
            width: 36%;
            color: #4a5568;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .summary-row td:last-child {
            color: #141c2e;
            font-size: 12px;
            font-weight: 700;
        }

        .summary-row-primary td:last-child {
            font-size: 12.5px;
            font-weight: 700;
        }

        .summary-row-secondary td:first-child {
            color: #5a6578;
        }

        .summary-row-secondary td:last-child {
            color: #2a3447;
            font-size: 11.5px;
            font-weight: 400;
        }

        .total-rule {
            display: inline-block;
            width: 92px;
            border-top: 3px solid #c8f24a;
            margin-bottom: 10px;
        }

        .total-label {
            color: #4a5568;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .total-value {
            margin-top: 5px;
            color: #171f45;
            font-size: 27px;
            line-height: 1.08;
            font-weight: 700;
        }

        .credit-note {
            margin-top: 8px;
            color: #087a4c;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.45;
        }

        .appointment {
            padding: 14px 0 13px;
            border-top: 1px solid #d5dde8;
            border-bottom: 1px solid #d5dde8;
        }

        .appointment .eyebrow {
            margin-bottom: 8px;
            font-size: 10.5px;
            font-weight: 700;
        }

        .appointment-date { width: 32%; padding-right: 20px; }
        .appointment-place { width: 68%; padding-top: 2px; }

        .date-card {
            color: #171f45;
            font-weight: 700;
        }

        .date-main {
            font-size: 24px;
            line-height: 1.14;
            font-weight: 700;
        }

        .time-main {
            margin-top: 4px;
            color: #5140a0;
            font-size: 20px;
            line-height: 1.18;
            font-weight: 700;
        }

        .place-name {
            color: #141c2e;
            font-size: 12px;
            line-height: 1.3;
            font-weight: 700;
        }

        .place-address {
            margin-top: 5px;
            color: #3a4659;
            font-size: 11px;
            line-height: 1.55;
            font-weight: 400;
        }

        .validity {
            margin-top: 14px;
            padding: 7px 0 7px 12px;
            border-left: 3px solid #c8f24a;
        }

        .validity-days {
            width: 88px;
            color: #171f45;
            font-size: 18px;
            line-height: 1.12;
            font-weight: 700;
        }

        .validity-days .label {
            margin-bottom: 2px;
        }

        .validity-copy {
            color: #3a4659;
            font-size: 11px;
            line-height: 1.55;
            font-weight: 400;
        }

        .steps {
            margin-top: 6px;
        }

        .step {
            page-break-inside: avoid;
            padding: 8px 0 9px;
            border-bottom: 1px solid #e3e8f1;
        }

        .first-step {
            page-break-inside: avoid;
            padding-top: 0;
        }

        .step-number {
            width: 40px;
            color: #5140a0;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.2;
        }

        .step-title {
            color: #141c2e;
            font-size: 12.5px;
            line-height: 1.28;
            font-weight: 700;
        }

        .step-copy {
            margin-top: 4px;
            color: #3a4659;
            font-size: 11.5px;
            line-height: 1.6;
            font-weight: 400;
        }

        .prep-intro {
            page-break-inside: avoid;
            margin-top: 16px;
            padding-top: 12px;
            border-top: 2px solid #141c2e;
        }

        .prep-title {
            margin-top: 3px;
            color: #141c2e;
            font-size: 19px;
            line-height: 1.2;
            font-weight: 700;
        }

        .study {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #d5dde8;
        }

        .study-compact {
            margin-top: 7px;
            padding-top: 7px;
            padding-bottom: 2px;
        }

        .study-long {
            page-break-inside: auto;
        }

        .first-study {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }

        .study-heading {
            page-break-after: avoid;
            page-break-inside: avoid;
            margin-bottom: 6px;
        }

        .study-heading-table {
            width: 100%;
            border-collapse: collapse;
        }

        .study-heading-table td {
            vertical-align: baseline;
        }

        .study-number {
            width: 30px;
            padding-right: 6px;
            color: #087a4c;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.3;
            white-space: nowrap;
        }

        .study-name {
            color: #141c2e;
            font-size: 13.5px;
            line-height: 1.34;
            font-weight: 700;
        }

        .prep-label {
            margin: 2px 0 4px 36px;
            color: #5140a0;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .study-compact .prep-label {
            margin-left: 36px;
            margin-bottom: 2px;
        }

        .instructions {
            margin-left: 36px;
            color: #1e2838;
            font-size: 12px;
            line-height: 1.66;
            font-weight: 400;
        }

        .study-compact .instructions {
            line-height: 1.5;
        }

        .instruction-line { margin: 0 0 5px; }

        .instruction-bullet-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }

        .instruction-bullet-table td {
            vertical-align: top;
        }

        .bullet-mark {
            width: 12px;
            color: #5140a0;
            font-size: 12px;
            line-height: 1.66;
        }

        .package-includes {
            page-break-inside: avoid;
            margin: 6px 0 6px 36px;
            padding: 6px 0 5px 10px;
            border-left: 3px solid #f59e0b;
        }

        .package-includes-title {
            margin: 0 0 4px;
            color: #9a3412;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .package-includes ul {
            margin-top: 0;
            color: #7c2d12;
            font-size: 11px;
            line-height: 1.5;
        }

        .support {
            margin-top: 18px;
            padding-top: 12px;
            border-top: 2px solid #5140a0;
        }

        .support .eyebrow {
            margin-bottom: 4px;
        }

        .support-title {
            color: #141c2e;
            font-size: 18px;
            line-height: 1.24;
            font-weight: 700;
        }

        .support-copy {
            margin-top: 5px;
            color: #3a4659;
            font-size: 11.5px;
            line-height: 1.58;
            font-weight: 400;
        }

        .support-phone {
            margin-top: 7px;
            color: #141c2e;
            font-size: 12px;
            line-height: 1.45;
        }

        .support-table {
            margin-top: 10px;
            border-top: 1px solid #d5dde8;
        }

        .support-table td {
            width: 25%;
            padding: 8px 10px 0 0;
        }

        .support-table .value {
            font-size: 11.5px;
        }

        .support-footer {
            margin-top: 8px;
            padding-top: 7px;
            border-top: 1px solid #d5dde8;
            color: #4a5568;
            font-size: 10px;
            line-height: 1.45;
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
                <table class="brand-lockup-table">
                    <tr>
                        <td class="brand-lockup-icon">
                            <img class="logo-img" src="{{ $famedic_logo_url }}" alt="">
                        </td>
                        <td>
                            <span class="brand-wordmark">famedic</span>
                        </td>
                    </tr>
                </table>
            @else
                <span class="brand-wordmark">famedic</span>
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
                    <p class="hero-text" style="margin-top:6px;">Tu cita quedó confirmada en {{ $laboratorio_marca }}. Aquí tienes tu comprobante e instrucciones para presentarte sin contratiempos.</p>
                @else
                    <p class="hero-text" style="margin-top:6px;">Tu compra quedó confirmada en {{ $laboratorio_marca }}. Aquí tienes tu comprobante e instrucciones para presentarte en sucursal con total tranquilidad.</p>
                @endif
            </td>
            <td class="hero-aside">
                <span class="confirmed"><span class="confirmed-dot"></span>Confirmada</span>
                <div class="hero-total-label">Total</div>
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
                <table class="summary-row summary-row-primary">
                    <tr><td>Laboratorio</td><td>{{ $laboratorio_marca }}</td></tr>
                </table>
                <table class="summary-row summary-row-primary">
                    <tr><td>Método</td><td>{{ $metodo_pago }}</td></tr>
                </table>
                <table class="summary-row summary-row-primary">
                    <tr><td>Fecha</td><td>{{ $fecha_compra }}</td></tr>
                </table>
                <table class="summary-row summary-row-secondary">
                    <tr><td>Estatus</td><td>{{ $estatus_pago }}</td></tr>
                </table>
                <table class="summary-row summary-row-secondary">
                    <tr><td>Subtotal</td><td>{{ $subtotal ?? $total_gross ?? $total }}</td></tr>
                </table>
                <table class="summary-row summary-row-secondary">
                    <tr><td>Descuento</td><td>{{ $catalog_discount ?? 'No aplica' }}</td></tr>
                </table>
                <table class="summary-row summary-row-secondary">
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
                    <div class="place-name">{{ $laboratorio_marca }}{{ ($branch_name ?? null) ? ' · '.$branch_name : '' }}</div>
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
                                <p class="step-copy"><strong>{{ $branch_name ?? $laboratorio_marca }}</strong> · {{ $branch_address ?? '-' }}</p>
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
            $instructionCharCount = $instructionLines->sum(fn ($line) => mb_strlen($line));
            $isCompactStudy = $instructionLines->count() === 1 && $instructionCharCount <= 80;
            $isLongStudy = $instructionLines->count() > 4 || $instructionCharCount > 320;
            $pkg = $study['feature_list'] ?? [];
        @endphp
        <div class="study {{ $loop->first ? 'first-study' : '' }} {{ $isCompactStudy ? 'study-compact' : '' }} {{ $isLongStudy ? 'study-long' : '' }}">
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
                    <span>Famedic</span>
                    <span style="color:#141c2e; font-weight:700;"> · Equipo Famedic</span>
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
