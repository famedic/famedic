@php
    $period = $reportData['period'] ?? [];
    $aging = $metrics['aging'] ?? [];
    $missing = $metrics['missing_files'] ?? [];
    $filters = $reportData['applied_filters'] ?? [];
    $generatedAt = localizedDate($run->started_at ?? now('America/Monterrey'))?->timezone('America/Monterrey')->isoFormat('D MMM Y h:mm a');
    $averageResponse = ($metrics['average_response_hours'] ?? null) === null ? 'Sin datos' : $metrics['average_response_hours'].' h';
    $oldestPending = data_get($metrics, 'oldest_pending.formatted_requested_at') ?: 'Sin solicitudes pendientes en el periodo';
    $pending = $metrics['pending_period'] ?? $metrics['pending_backlog'] ?? 0;
    $overdue = $metrics['overdue_period'] ?? $metrics['overdue_backlog'] ?? 0;
    $scopeRows = [
        ['Marca', $filters['brand'] ?? 'Todas'],
        ['Sucursal', $filters['laboratory_store_id'] ?? 'Todas'],
        ['Estado', $filters['status'] ?? 'Todos'],
        ['Zona horaria', $period['timezone'] ?? 'America/Monterrey'],
        ['Generado', $generatedAt],
    ];
    $cards = [
        ['label' => 'Solicitudes recibidas', 'value' => $metrics['received'] ?? 0, 'color' => '#2563eb', 'bg' => '#eff6ff'],
        ['label' => 'Facturas completadas', 'value' => $metrics['completed'] ?? 0, 'color' => '#15803d', 'bg' => '#f0fdf4'],
        ['label' => 'Pendientes del periodo', 'value' => $pending, 'color' => '#b45309', 'bg' => '#fffbeb'],
        ['label' => 'Atrasadas del periodo', 'value' => $overdue, 'color' => '#b91c1c', 'bg' => '#fef2f2'],
        ['label' => 'Cumplimiento', 'value' => ($metrics['compliance_percent'] ?? 0).'%', 'color' => '#334155', 'bg' => '#f8fafc'],
        ['label' => 'Tiempo promedio', 'value' => $averageResponse, 'color' => '#334155', 'bg' => '#f8fafc'],
    ];
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte de facturación de laboratorio</title>
</head>
<body style="margin:0; padding:0; background:#f3f6fa; color:#0f172a; font-family:Arial, Helvetica, sans-serif;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent;">
        Resumen de facturación. Consulta el resumen y el archivo Excel del periodo seleccionado.
    </div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f6fa; margin:0; padding:24px 0;">
        <tr>
            <td align="center" style="padding:0 12px;">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:640px; background:#ffffff; border-collapse:collapse;">
                    <tr>
                        <td style="padding:28px 28px 20px 28px; background:#0f172a;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="font-size:20px; line-height:26px; font-weight:700; color:#ffffff;">Famedic</td>
                                    <td align="right" style="font-size:13px; line-height:18px; color:#cbd5e1;">{{ $reportData['run_type_label'] ?? ucfirst((string) $run->run_type) }}</td>
                                </tr>
                            </table>
                            <div style="font-size:24px; line-height:31px; font-weight:700; color:#ffffff; margin-top:18px;">Reporte de facturación de laboratorio</div>
                            <div style="font-size:14px; line-height:20px; color:#cbd5e1; margin-top:6px;">{{ $schedule->name }}</div>
                            @if ($isTest)
                                <div style="font-size:13px; line-height:18px; color:#fde68a; margin-top:10px;">Prueba de configuración y contenido.</div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 28px 10px 28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eef6ff; border-left:4px solid #2563eb;">
                                <tr>
                                    <td style="padding:18px 18px;">
                                        <div style="font-size:13px; line-height:18px; color:#1d4ed8; font-weight:700; text-transform:uppercase;">Periodo analizado</div>
                                        <div style="font-size:18px; line-height:25px; color:#0f172a; font-weight:700; margin-top:4px;">{{ $period['label'] ?? '' }}</div>
                                        <div style="font-size:14px; line-height:20px; color:#334155; margin-top:6px;">Todas las métricas corresponden únicamente a este periodo.</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 20px 4px 20px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                @foreach (array_chunk($cards, 2) as $row)
                                    <tr>
                                        @foreach ($row as $card)
                                            <td width="50%" valign="top" style="padding:8px;">
                                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:{{ $card['bg'] }}; border:1px solid #e2e8f0;">
                                                    <tr>
                                                        <td style="padding:16px;">
                                                            <div style="font-size:13px; line-height:18px; color:#475569;">{{ $card['label'] }}</div>
                                                            <div style="font-size:26px; line-height:32px; color:{{ $card['color'] }}; font-weight:700; margin-top:6px;">{{ $card['value'] }}</div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 0 28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                                <tr>
                                    <td colspan="2" style="font-size:16px; line-height:22px; color:#0f172a; font-weight:700; padding:10px 0;">Alcance del reporte</td>
                                </tr>
                                @foreach ($scopeRows as [$label, $value])
                                    <tr>
                                        <td width="34%" style="font-size:13px; line-height:18px; color:#64748b; padding:8px 0; border-top:1px solid #e2e8f0;">{{ $label }}</td>
                                        <td style="font-size:13px; line-height:18px; color:#0f172a; padding:8px 0; border-top:1px solid #e2e8f0;">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px 0 28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8fafc; border:1px solid #e2e8f0;">
                                <tr>
                                    <td style="padding:16px;">
                                        <div style="font-size:14px; line-height:20px; color:#0f172a; font-weight:700;">Archivo Excel</div>
                                        <div style="font-size:13px; line-height:19px; color:#334155; margin-top:4px;">
                                            @if ($attachmentPath)
                                                El archivo reporte-facturacion-laboratorio.xlsx está adjunto a este correo.
                                            @elseif ($downloadUrl)
                                                El archivo reporte-facturacion-laboratorio.xlsx está disponible mediante enlace temporal protegido.
                                            @else
                                                Esta configuración no adjuntó archivo Excel.
                                            @endif
                                        </div>
                                        @if ($metrics['detail_truncated'] ?? false)
                                            <div style="font-size:13px; line-height:19px; color:#92400e; margin-top:8px;">Detalle limitado a {{ $metrics['detail_exported_rows'] ?? 0 }} de {{ $metrics['detail_total_rows'] ?? 0 }} filas.</div>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px 0 28px;">
                            <div style="font-size:13px; line-height:19px; color:#475569;">Pendiente más antigua: {{ $oldestPending }}</div>
                            <div style="font-size:13px; line-height:19px; color:#475569; margin-top:4px;">Archivos faltantes: PDF {{ $missing['missing_pdf'] ?? 0 }}, XML {{ $missing['missing_xml'] ?? 0 }}, ambos {{ $missing['missing_both'] ?? 0 }}.</div>
                            <div style="font-size:13px; line-height:19px; color:#475569; margin-top:4px;">Antigüedad: dentro de plazo {{ $aging['within_sla'] ?? 0 }}, 1-3 días {{ $aging['overdue_1_3'] ?? 0 }}, 4-7 días {{ $aging['overdue_4_7'] ?? 0 }}, más de 7 días {{ $aging['overdue_more_7'] ?? 0 }}.</div>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:24px 28px 28px 28px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" bgcolor="#0f172a" style="background:#0f172a;">
                                        <a href="{{ $moduleUrl }}" style="display:inline-block; padding:12px 20px; font-size:14px; line-height:18px; color:#ffffff; text-decoration:none; font-weight:700;">Abrir módulo de facturación</a>
                                    </td>
                                </tr>
                            </table>
                            @if ($downloadUrl)
                                <div style="font-size:12px; line-height:18px; color:#64748b; margin-top:12px;">Enlace temporal del Excel: <a href="{{ $downloadUrl }}" style="color:#2563eb;">abrir archivo</a></div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                            <div style="font-size:12px; line-height:18px; color:#64748b;">Generado el {{ $generatedAt }} · America/Monterrey.</div>
                            <div style="font-size:12px; line-height:18px; color:#64748b; margin-top:4px;">Este correo fue generado automáticamente. No respondas a este mensaje.</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
