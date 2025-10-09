<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido de Laboratorio - {{ $laboratoryPurchase->gda_order_id }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #3f3f46;
            background: #fff;
            margin: 0;
            padding: 0;
        }
        
        .container {
            padding: 24px;
            background: white;
            margin: 0 auto;
            max-width: 800px;
        }
        
        /* Date section */
        .date-section {
            text-align: right;
            margin-bottom: 24px;
        }
        
        .date-code {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-family: monospace;
            background: #fafafa;
            padding: 6px 12px;
            border-radius: 6px;
            color: #71717a;
        }
        
        /* Header section */
        .pdf-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e4e4e7;
        }
        
        .famedic-logo {
            max-height: 24px;
            max-width: auto;
        }
        
        .famedic-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .famedic-text {
            font-family: 'DejaVu Sans', sans-serif;
            font-weight: 600;
            font-size: 16px;
            color: #1E1A3D;
            margin-left: -4px;
        }
        
        .header-info {
            text-align: right;
            font-size: 12px;
            color: #71717a;
        }
        
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            gap: 24px;
        }
        
        .thank-you-title {
            font-size: 48px;
            line-height: 3.8rem;
            font-weight: 500;
            color: #1E1A3D;
            margin: 0;
            flex: 1;
            font-family: 'DejaVu Sans', sans-serif;
            letter-spacing: -0.025em;
            border: none;
            outline: none;
        }
        
        .brand-logo-card {
            background: #fafafa;
            border-radius: 12px;
            padding: 16px 24px;
            border: 1px solid #e4e4e7;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .brand-logo {
            max-height: 80px;
            max-width: 160px;
            object-fit: contain;
        }
        
        /* Folio section */
        .folio-section {
            margin-bottom: 40px;
        }
        
        .folio-label {
            font-size: 16px;
            line-height: 1.75;
            font-weight: 600;
            color: #1E1A3D;
            margin-bottom: 8px;
            font-family: 'DejaVu Sans', sans-serif;
        }
        
        .folio-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #26214e;
            color: white;
            padding: 2px 6px;
            border-radius: 6px;
            font-size: 48px;
            font-weight: 500;
            font-family: 'DejaVu Sans', sans-serif;
            width: min-content;
        }
        
        .folio-badge svg {
            fill: #d5f278;
            width: 40px;
            height: 40px;
        }
        
        
        /* Details grid */
        .details-grid {
            display: table;
            width: 100%;
            margin-bottom: 32px;
        }
        
        .details-row {
            display: table-row;
        }
        
        .details-cell {
            display: table-cell;
            width: 50%;
            padding: 20px 24px 20px 0;
            vertical-align: top;
        }
        
        .label-with-icon {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
            font-weight: 600;
            color: #1E1A3D;
            margin-bottom: 16px;
        }
        
        .icon {
            width: 24px;
            height: 24px;
            fill: #d4d4d8;
        }
        
        .detail-text {
            color: #71717a;
            margin-bottom: 4px;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .detail-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(14, 165, 233, 0.15);
            color: #0369a1;
            padding: 2px 6px;
            border-radius: 6px;
            font-size: 13px;
            line-height: 1.25;
            font-weight: 500;
            margin-top: 8px;
            font-family: 'DejaVu Sans', sans-serif;
        }
        
        .detail-notes {
            font-size: 12px;
            color: #71717a;
            margin-top: 8px;
            font-style: italic;
        }
        
        /* Divider */
        .divider {
            border: none;
            border-top: 1px solid #e4e4e7;
            margin: 32px 0;
        }
        
        /* Items section */
        .item {
            padding: 40px 0;
            border-bottom: 1px solid #e4e4e7;
        }
        
        .item-name {
            font-size: 16px;
            font-weight: 600;
            color: #1E1A3D;
            margin-bottom: 8px;
        }
        
        .item-description {
            color: #71717a;
            margin-bottom: 24px;
            max-width: 600px;
            font-size: 14px;
        }
        
        .item-price-row {
            display: flex;
            gap: 48px;
            font-size: 14px;
        }
        
        .price-label {
            font-weight: 600;
            color: #1E1A3D;
        }
        
        .price-value {
            color: #71717a;
            margin-left: 8px;
        }
        
        /* Totals section */
        .totals-section {
            margin-top: 24px;
            text-align: right;
        }
        
        .total-row {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 24px;
        }
        
        .total-label {
            font-size: 16px;
            font-weight: 600;
            color: #1E1A3D;
            margin-right: 24px;
        }
        
        .total-value {
            font-size: 16px;
            font-weight: 600;
            color: #1E1A3D;
        }
        
        /* Credit card brand */
        .card-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .card-brand {
            font-weight: 500;
        }
        
        .card-number {
            font-family: monospace;
            background: #fafafa;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        /* Odessa payment */
        .odessa-payment {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .odessa-logo {
            width: 24px;
            height: 24px;
        }
        
        .odessa-label {
            color: #71717a;
        }
        
        .odessa-subtitle {
            font-size: 11px;
            color: #ea580c;
            margin-top: 2px;
        }
        
        /* Footer */
        .footer {
            margin-top: 48px;
            padding-top: 24px;
            border-top: 1px solid #e4e4e7;
            text-align: center;
            font-size: 11px;
            color: #a1a1aa;
        }
        
        /* Laboratory stores table */
        .stores-section {
            margin-top: 40px;
            margin-bottom: 40px;
        }
        
        .stores-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 16px;
            font-size: 12px;
            border: 1px solid #e4e4e7;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .stores-table th,
        .stores-table td {
            border-right: 1px solid #e4e4e7;
            border-bottom: 1px solid #e4e4e7;
            padding: 8px 12px;
            text-align: left;
            vertical-align: top;
        }
        
        .stores-table th:last-child,
        .stores-table td:last-child {
            border-right: none;
        }
        
        .stores-table tr:last-child td {
            border-bottom: none;
        }
        
        .stores-table th {
            background-color: #f9fafb;
            font-weight: 600;
            color: #1E1A3D;
            font-size: 13px;
            border-bottom: 1px solid #e4e4e7;
        }
        
        .stores-table td {
            color: #71717a;
            line-height: 1.4;
        }
        
        .stores-table tr:nth-child(even) td {
            background-color: #fafafa;
        }

        /* Utility classes */
        .mt-4 { margin-top: 16px; }
        .text-xs { font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <!-- PDF Header -->
        <div class="pdf-header">
            <div class="famedic-brand">
                <img src="{{ public_path('images/logo.png') }}" alt="Famedic" class="famedic-logo">
                <span class="famedic-text">Famedic</span>
            </div>
            <div class="header-info">
                <div>Teléfono de Ayuda General: <strong>(812)-860-1893</strong></div>
                <div style="margin-top: 4px; display: flex; align-items: center; gap: 4px; justify-content: flex-end;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="#71717a">
                        <path d="M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z"/>
                    </svg>
                    <span>{{ $laboratoryPurchase->formatted_created_at }}</span>
                </div>
            </div>
        </div>

        <!-- Header Section -->
        <div class="header-section">
            <h1 class="thank-you-title">¡Gracias por tu pedido!</h1>
            @php
                $brandValue = $laboratoryPurchase->brand->value;
                $logoPath = public_path('images/gda/GDA-' . strtoupper($brandValue) . '.png');
            @endphp
            <div class="brand-logo-card">
                <img src="{{ $logoPath }}" 
                     alt="{{ $laboratoryPurchase->brand->label() }}" 
                     class="brand-logo">
            </div>
        </div>

        <!-- Folio Section -->
        <div class="folio-section">
            <div class="folio-label">Folio</div>
            <div class="folio-badge">
                <svg viewBox="0 0 24 24">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M3 4.875C3 3.83947 3.83947 3 4.875 3H9.375C10.4105 3 11.25 3.83947 11.25 4.875V9.375C11.25 10.4105 10.4105 11.25 9.375 11.25H4.875C3.83947 11.25 3 10.4105 3 9.375V4.875ZM6 6.75V8.25H8.25V6.75H6ZM14.625 3H19.125C20.1605 3 21 3.83947 21 4.875V9.375C21 10.4105 20.1605 11.25 19.125 11.25H14.625C13.5895 11.25 12.75 10.4105 12.75 9.375V4.875C12.75 3.83947 13.5895 3 14.625 3ZM15.75 6.75V8.25H18V6.75H15.75ZM3 14.625C3 13.5895 3.83947 12.75 4.875 12.75H9.375C10.4105 12.75 11.25 13.5895 11.25 14.625V19.125C11.25 20.1605 10.4105 21 9.375 21H4.875C3.83947 21 3 20.1605 3 19.125V14.625ZM6 16.5V18H8.25V16.5H6ZM13.5 12.75C12.8787 12.75 12.375 13.2537 12.375 13.875C12.375 14.4963 12.8787 15 13.5 15H15V16.5C15 17.1213 15.4787 17.625 16.125 17.625C16.7463 17.625 17.25 17.1213 17.25 16.5V15H18.75C19.3713 15 19.875 14.4963 19.875 13.875C19.875 13.2537 19.3713 12.75 18.75 12.75H17.25V13.875C17.25 13.2537 16.7463 12.75 16.125 12.75C15.4787 12.75 15 13.2537 15 13.875V12.75H13.5ZM15 18.75C15 18.1287 15.4787 17.625 16.125 17.625C16.7463 17.625 17.25 18.1287 17.25 18.75V20.25C17.25 20.8713 16.7463 21.375 16.125 21.375C15.4787 21.375 15 20.8713 15 20.25V18.75ZM18.75 17.625C18.1287 17.625 17.625 18.1287 17.625 18.75C17.625 19.3713 18.1287 19.875 18.75 19.875H20.25C20.8713 19.875 21.375 19.3713 21.375 18.75C21.375 18.1287 20.8713 17.625 20.25 17.625H18.75Z"/>
                </svg>
                {{ $laboratoryPurchase->gda_order_id }}
            </div>
        </div>


        <!-- Details Grid -->
        <div class="details-grid">
            <div class="details-row">
                <!-- Patient Information -->
                <div class="details-cell">
                    <div class="label-with-icon">
                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M4.5 3.75C2.84315 3.75 1.5 5.09315 1.5 6.75V17.25C1.5 18.9069 2.84315 20.25 4.5 20.25H19.5C21.1569 20.25 22.5 18.9069 22.5 17.25V6.75C22.5 5.09315 21.1569 3.75 19.5 3.75H4.5ZM8.625 6.75C7.38236 6.75 6.375 7.75736 6.375 9C6.375 10.2426 7.38236 11.25 8.625 11.25C9.86764 11.25 10.875 10.2426 10.875 9C10.875 7.75736 9.86764 6.75 8.625 6.75ZM4.75191 15.4528C5.3309 13.8765 6.84542 12.75 8.62496 12.75C10.4045 12.75 11.919 13.8765 12.498 15.4528C12.6271 15.8043 12.4771 16.1972 12.1466 16.3733C11.0958 16.9331 9.89627 17.25 8.62496 17.25C7.35364 17.25 6.15413 16.9331 5.10331 16.3733C4.77278 16.1972 4.62279 15.8043 4.75191 15.4528ZM15 8.25C14.5858 8.25 14.25 8.58579 14.25 9C14.25 9.41421 14.5858 9.75 15 9.75H18.75C19.1642 9.75 19.5 9.41421 19.5 9C19.5 8.58579 19.1642 8.25 18.75 8.25H15ZM14.25 12C14.25 11.5858 14.5858 11.25 15 11.25H18.75C19.1642 11.25 19.5 11.5858 19.5 12C19.5 12.4142 19.1642 12.75 18.75 12.75H15C14.5858 12.75 14.25 12.4142 14.25 12ZM15 14.25C14.5858 14.25 14.25 14.5858 14.25 15C14.25 15.4142 14.5858 15.75 15 15.75H18.75C19.1642 15.75 19.5 15.4142 19.5 15C19.5 14.5858 19.1642 14.25 18.75 14.25H15Z"/>
                        </svg>
                        Paciente
                    </div>
                    <div>
                        <div class="detail-text">{{ $laboratoryPurchase->temporarly_hide_gda_order_id ? 'Nombre de paciente pendiente' : $laboratoryPurchase->full_name }}</div>
                        <div class="detail-text">{{ $laboratoryPurchase->phone }}</div>
                        <div class="detail-text">{{ $laboratoryPurchase->formatted_birth_date }}</div>
                        <div class="detail-text">{{ $laboratoryPurchase->formatted_gender }}</div>
                    </div>
                </div>

                <!-- Appointment/Stores Information -->
                <div class="details-cell">
                    @if($laboratoryPurchase->laboratoryAppointment)
                        <div class="label-with-icon">
                            <svg class="icon" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12.75 12.75C12.75 13.1642 12.4142 13.5 12 13.5C11.5858 13.5 11.25 13.1642 11.25 12.75C11.25 12.3358 11.5858 12 12 12C12.4142 12 12.75 12.3358 12.75 12.75Z"/>
                                <path d="M7.5 15.75C7.91421 15.75 8.25 15.4142 8.25 15C8.25 14.5858 7.91421 14.25 7.5 14.25C7.08579 14.25 6.75 14.5858 6.75 15C6.75 15.4142 7.08579 15.75 7.5 15.75Z"/>
                                <path d="M8.25 17.25C8.25 17.6642 7.91421 18 7.5 18C7.08579 18 6.75 17.6642 6.75 17.25C6.75 16.8358 7.08579 16.5 7.5 16.5C7.91421 16.5 8.25 16.8358 8.25 17.25Z"/>
                                <path d="M9.75 15.75C10.1642 15.75 10.5 15.4142 10.5 15C10.5 14.5858 10.1642 14.25 9.75 14.25C9.33579 14.25 9 14.5858 9 15C9 15.4142 9.33579 15.75 9.75 15.75Z"/>
                                <path d="M10.5 17.25C10.5 17.6642 10.1642 18 9.75 18C9.33579 18 9 17.6642 9 17.25C9 16.8358 9.33579 16.5 9.75 16.5C10.1642 16.5 10.5 16.8358 10.5 17.25Z"/>
                                <path d="M12 15.75C12.4142 15.75 12.75 15.4142 12.75 15C12.75 14.5858 12.4142 14.25 12 14.25C11.5858 14.25 11.25 14.5858 11.25 15C11.25 15.4142 11.5858 15.75 12 15.75Z"/>
                                <path d="M12.75 17.25C12.75 17.6642 12.4142 18 12 18C11.5858 18 11.25 17.6642 11.25 17.25C11.25 16.8358 11.5858 16.5 12 16.5C12.4142 16.5 12.75 16.8358 12.75 17.25Z"/>
                                <path d="M14.25 15.75C14.6642 15.75 15 15.4142 15 15C15 14.5858 14.6642 14.25 14.25 14.25C13.8358 14.25 13.5 14.5858 13.5 15C13.5 15.4142 13.8358 15.75 14.25 15.75Z"/>
                                <path d="M15 17.25C15 17.6642 14.6642 18 14.25 18C13.8358 18 13.5 17.6642 13.5 17.25C13.5 16.8358 13.8358 16.5 14.25 16.5C14.6642 16.5 15 16.8358 15 17.25Z"/>
                                <path d="M16.5 15.75C16.9142 15.75 17.25 15.4142 17.25 15C17.25 14.5858 16.9142 14.25 16.5 14.25C16.0858 14.25 15.75 14.5858 15.75 15C15.75 15.4142 16.0858 15.75 16.5 15.75Z"/>
                                <path d="M17.25 17.25C17.25 17.6642 16.9142 18 16.5 18C16.0858 18 15.75 17.6642 15.75 17.25C15.75 16.8358 16.0858 16.5 16.5 16.5C16.9142 16.5 17.25 16.8358 17.25 17.25Z"/>
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M6.75 2.25C7.16421 2.25 7.5 2.58579 7.5 3V4.5H16.5V3C16.5 2.58579 16.8358 2.25 17.25 2.25C17.6642 2.25 18 2.58579 18 3V4.5H19.5C20.7426 4.5 21.75 5.50736 21.75 6.75V19.5C21.75 20.7426 20.7426 21.75 19.5 21.75H4.5C3.25736 21.75 2.25 20.7426 2.25 19.5V6.75C2.25 5.50736 3.25736 4.5 4.5 4.5H6V3C6 2.58579 6.33579 2.25 6.75 2.25ZM3.75 10.5V19.5C3.75 19.9142 4.08579 20.25 4.5 20.25H19.5C19.9142 20.25 20.25 19.9142 20.25 19.5V10.5H3.75Z"/>
                            </svg>
                            Cita
                        </div>
                        <div>
                            <div class="detail-text">{{ $laboratoryPurchase->laboratoryAppointment->laboratoryStore->name }}</div>
                            <div class="detail-badge">{{ $laboratoryPurchase->laboratoryAppointment->formatted_appointment_date }}</div>
                            <div class="detail-text mt-4">{{ $laboratoryPurchase->laboratoryAppointment->laboratoryStore->address }}</div>
                            @if($laboratoryPurchase->laboratoryAppointment->notes)
                                <div class="detail-notes">{{ $laboratoryPurchase->laboratoryAppointment->notes }}</div>
                            @endif
                        </div>
                    @else
                        <div class="label-with-icon">
                            <svg class="icon" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M5.22335 2.25C4.72607 2.25 4.24916 2.44754 3.89752 2.79917L2.59835 4.09835C1.13388 5.56282 1.13388 7.93718 2.59835 9.40165C3.93551 10.7388 6.03124 10.8551 7.50029 9.75038C8.12669 10.2206 8.90598 10.5 9.75 10.5C10.5941 10.5 11.3736 10.2205 12 9.75016C12.6264 10.2205 13.4059 10.5 14.25 10.5C15.094 10.5 15.8733 10.2206 16.4997 9.75038C17.9688 10.8551 20.0645 10.7388 21.4016 9.40165C22.8661 7.93718 22.8661 5.56282 21.4016 4.09835L20.1025 2.79918C19.7508 2.44755 19.2739 2.25 18.7767 2.25L5.22335 2.25Z"/>
                                <path d="M3 20.25V11.4951C4.42021 12.1686 6.0799 12.1681 7.50044 11.4944C8.18265 11.8183 8.94611 12 9.75 12C10.5541 12 11.3177 11.8182 12 11.4942C12.6823 11.8182 13.4459 12 14.25 12C15.0539 12 15.8173 11.8183 16.4996 11.4944C17.9201 12.1681 19.5798 12.1686 21 11.4951V20.25C21 20.6642 20.6642 21 20.25 21H3.75C3.33579 21 3 20.6642 3 20.25ZM9 13.5C8.58579 13.5 8.25 13.8358 8.25 14.25V17.25C8.25 17.6642 8.58579 18 9 18H10.5C10.9142 18 11.25 17.6642 11.25 17.25V14.25C11.25 13.8358 10.9142 13.5 10.5 13.5H9ZM13.5 13.5C13.0858 13.5 12.75 13.8358 12.75 14.25V17.25C12.75 17.6642 13.0858 18 13.5 18H15C15.4142 18 15.75 17.6642 15.75 17.25V14.25C15.75 13.8358 15.4142 13.5 15 13.5H13.5Z"/>
                            </svg>
                            Sucursales
                        </div>
                        <div>
                            <div class="detail-text" style="color: #009ad8; font-weight: 500;">Puedes acudir a cualquiera de las sucursales de la marca a realizarte tus estudios.</div>
                            <div class="detail-text" style="margin-top: 8px; font-style: italic;">Consulta la lista completa de sucursales al final de este documento.</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <hr class="divider">

        <!-- Payment and Address -->
        <div class="details-grid">
            <div class="details-row">
                <!-- Payment Method -->
                <div class="details-cell">
                    <div class="label-with-icon">
                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M4.5 3.75C2.84315 3.75 1.5 5.09315 1.5 6.75V7.5H22.5V6.75C22.5 5.09315 21.1569 3.75 19.5 3.75H4.5Z"/>
                            <path d="M22.5 9.75H1.5V17.25C1.5 18.9069 2.84315 20.25 4.5 20.25H19.5C21.1569 20.25 22.5 18.9069 22.5 17.25V9.75ZM4.5 13.5C4.5 13.0858 4.83579 12.75 5.25 12.75H11.25C11.6642 12.75 12 13.0858 12 13.5C12 13.9142 11.6642 14.25 11.25 14.25H5.25C4.83579 14.25 4.5 13.9142 4.5 13.5ZM5.25 15.75C4.83579 15.75 4.5 16.0858 4.5 16.5C4.5 16.9142 4.83579 17.25 5.25 17.25H8.25C8.66421 17.25 9 16.9142 9 16.5C9 16.0858 8.66421 15.75 8.25 15.75H5.25Z"/>
                        </svg>
                        Método de pago
                    </div>
                    <div>
                        @if($laboratoryPurchase->transactions->count() == 0)
                            <div class="detail-text">No registrado</div>
                        @else
                            @php $transaction = $laboratoryPurchase->transactions->first(); @endphp
                            @if($transaction->payment_method === 'odessa')
                                <div class="odessa-payment">
                                    <img src="{{ public_path('images/odessa.png') }}" alt="odessa" class="odessa-logo">
                                    <div>
                                        <div class="odessa-label">ODESSA</div>
                                        <div class="odessa-subtitle">Cobro a caja de ahorro</div>
                                    </div>
                                </div>
                            @else
                                <div class="card-info">
                                    <span class="card-brand">{{ ucfirst($transaction->details['card_brand'] ?? 'Tarjeta') }}</span>
                                    <span class="card-number">{{ $transaction->details['card_last_four'] ?? '****' }}</span>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>

                <!-- Address -->
                <div class="details-cell">
                    <div class="label-with-icon">
                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M8.16147 2.58076C8.68934 2.31683 9.31066 2.31683 9.83853 2.58076L14.8323 5.07765C14.9379 5.13043 15.0621 5.13043 15.1677 5.07765L19.0365 3.14326C20.2832 2.51992 21.75 3.42647 21.75 4.82031V17.3047C21.75 18.0149 21.3487 18.6642 20.7135 18.9818L15.8385 21.4193C15.3107 21.6832 14.6893 21.6832 14.1615 21.4193L9.16771 18.9224C9.06213 18.8696 8.93787 18.8696 8.8323 18.9224L4.96353 20.8568C3.71683 21.4801 2.25 20.5736 2.25 19.1797V6.69531C2.25 5.98512 2.65125 5.33587 3.28647 5.01826L8.16147 2.58076ZM9 6.00002C9.41421 6.00002 9.75 6.3358 9.75 6.75002V15C9.75 15.4142 9.41421 15.75 9 15.75C8.58579 15.75 8.25 15.4142 8.25 15V6.75002C8.25 6.3358 8.58579 6.00002 9 6.00002ZM15.75 9.00002C15.75 8.5858 15.4142 8.25002 15 8.25002C14.5858 8.25002 14.25 8.5858 14.25 9.00002V17.25C14.25 17.6642 14.5858 18 15 18C15.4142 18 15.75 17.6642 15.75 17.25V9.00002Z"/>
                        </svg>
                        Dirección
                    </div>
                    <div>
                        <div class="detail-text">{{ $laboratoryPurchase->street }} {{ $laboratoryPurchase->number }}</div>
                        <div class="detail-text">{{ $laboratoryPurchase->neighborhood }}, {{ $laboratoryPurchase->zipcode }}</div>
                        <div class="detail-text">{{ $laboratoryPurchase->city }}, {{ $laboratoryPurchase->state }}</div>
                        @if($laboratoryPurchase->additional_references)
                            <div class="detail-notes">{{ $laboratoryPurchase->additional_references }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <hr class="divider">

        <!-- Items Section -->
        @foreach($laboratoryPurchase->laboratoryPurchaseItems as $item)
        <div class="item">
            <div class="item-name">{{ $item->name }}</div>
            @if($item->indications)
                <div class="item-description">{{ $item->indications }}</div>
            @endif
            <div class="item-price-row">
                <div>
                    <span class="price-label">Precio</span>
                    <span class="price-value">{{ $item->formatted_price }}</span>
                </div>
            </div>
        </div>
        @endforeach

        <!-- Totals Section -->
        <div class="totals-section">
            <div class="total-row">
                <div class="total-label">Total</div>
                <div class="total-value">{{ $laboratoryPurchase->formatted_total }}</div>
            </div>
        </div>

        <!-- Laboratory Stores Table -->
        <div class="stores-section">
            <div class="folio-label">
                Sucursales Disponibles - {{ $laboratoryPurchase->brand->label() }}
            </div>
            <table class="stores-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">Nombre</th>
                        <th style="width: 50%;">Dirección</th>
                        <th style="width: 20%;">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($laboratoryStores as $store)
                    <tr>
                        <td>{{ $store->name }}</td>
                        <td>{{ $store->address }}</td>
                        <td>{{ $store->state }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Este documento fue generado automáticamente por Famedic el {{ localizedDate(now())->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}</p>
            <p>Para más información, visita nuestro sitio web o contacta a nuestro equipo de soporte.</p>
            <p>Teléfono de Ayuda General: <strong>(812)-860-1893</strong></p>
        </div>
    </div>
</body>
</html>
