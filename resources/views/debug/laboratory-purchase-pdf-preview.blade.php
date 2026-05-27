<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vista previa PDF — pedido #{{ $laboratoryPurchase->id }}</title>
    <style>
        body { margin: 0; padding: 16px; background: #edf2f7; font-family: system-ui, sans-serif; }
        .toolbar {
            margin: 0 0 16px;
            padding: 12px 16px;
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            font-size: 14px;
            color: #856404;
        }
        .toolbar strong { display: block; margin-bottom: 8px; }
        .toolbar a {
            display: inline-block;
            margin: 4px 8px 4px 0;
            padding: 6px 10px;
            background: #fff;
            border: 1px solid #d69e2e;
            border-radius: 6px;
            color: #744210;
            text-decoration: none;
            font-size: 13px;
        }
        .frame-wrap {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .frame-label {
            padding: 8px 16px;
            font-size: 12px;
            color: #718096;
            border-bottom: 1px solid #e2e8f0;
            background: #f7fafc;
        }
        .pdf-content { padding: 0; }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>Vista previa PDF (DomPDF · solo local)</strong>
        Pedido #{{ $laboratoryPurchase->id }} · Folio {{ $laboratoryPurchase->gda_order_id ?? '—' }}
        · Variante: <code>{{ $variant }}</code> · Plantilla: <code>{{ $withAppointment ? 'con-cita' : 'sin-cita' }}</code>
        <div style="margin-top:10px;">
            <a href="{{ $previewUrls['html_auto'] }}">HTML · auto</a>
            <a href="{{ $previewUrls['html_without'] }}">HTML · sin cita</a>
            <a href="{{ $previewUrls['pdf_auto'] }}" target="_blank" rel="noopener">PDF · auto</a>
            <a href="{{ $previewUrls['pdf_without'] }}" target="_blank" rel="noopener">PDF · sin cita</a>
            <a href="{{ $previewUrls['download'] }}" target="_blank" rel="noopener">Descarga real (auth)</a>
            <a href="{{ $previewUrls['email'] }}" target="_blank" rel="noopener">Correo confirmación</a>
        </div>
    </div>

    <div class="frame-wrap">
        <div class="frame-label">Vista HTML (misma plantilla que el PDF DomPDF)</div>
        <div class="pdf-content">
            {!! $pdfContent !!}
        </div>
    </div>
</body>
</html>
