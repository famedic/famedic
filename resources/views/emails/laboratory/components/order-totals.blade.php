@if (!empty($has_coupon_credit) || !empty($catalog_discount))
<p style="margin:12px 0 4px;color:#3d4852;font-size:15px;line-height:1.5;">
    <strong>Desglose de tu compra</strong>
</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px;border-collapse:collapse;font-size:15px;line-height:1.5;color:#3d4852;">
    <tr>
        <td style="padding:4px 0;">Subtotal</td>
        <td style="padding:4px 0;text-align:right;white-space:nowrap;">{{ $subtotal }}</td>
    </tr>
    @if (!empty($catalog_discount))
    <tr>
        <td style="padding:4px 0;">Descuento</td>
        <td style="padding:4px 0;text-align:right;white-space:nowrap;">−{{ $catalog_discount }}</td>
    </tr>
    @endif
    @if (!empty($has_coupon_credit) && !empty($coupon_discount))
    <tr>
        <td style="padding:4px 0;">Crédito a favor</td>
        <td style="padding:4px 0;text-align:right;white-space:nowrap;">−{{ $coupon_discount }}</td>
    </tr>
    @endif
    <tr>
        <td style="padding:8px 0 4px;font-weight:600;">Total</td>
        <td style="padding:8px 0 4px;text-align:right;font-weight:600;white-space:nowrap;">{{ $total }}</td>
    </tr>
</table>
@if (!empty($credit_applied_message))
<p style="margin:0 0 12px;padding:10px 12px;border-radius:8px;background:#f5f3ff;border:1px solid #ddd6fe;color:#4c1d95;font-size:14px;line-height:1.5;">
    {{ $credit_applied_message }}
</p>
@endif
@else
<p style="margin:0 0 4px;color:#3d4852;font-size:16px;line-height:1.5;">
    💰Total pagado: <b>{{ $total }}</b>
</p>
@endif
