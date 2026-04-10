{{--
  Logos: mismas rutas que el sitio; el host lo define config('famedic.email_public_url') (p. ej. https://famedic.com.mx).
  - Famedic: /images/logo.png (ApplicationLogo, modo claro).
  - Laboratorio: /images/gda/{archivo} (LaboratoryBrandSelection + LaboratoryBrand::imageSrc()).
--}}
{{-- Variables: $famedic_logo_url, $laboratorio_logo_url, $laboratorio_marca --}}

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border-collapse:collapse;border-bottom:1px solid #e2e8f0;padding-bottom:20px;">
<tr>
<td align="center" style="padding:0;">
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;border-collapse:collapse;">
<tr>
<td align="center" style="padding:0 20px 12px 0;vertical-align:middle;">
<img src="{{ $famedic_logo_url }}" alt="Famedic" width="50" style="max-width:50px;width:50px;height:auto;border:0;display:block;margin:0 auto;object-fit:contain;">
</td>
<td align="center" style="padding:0 0 12px 20px;vertical-align:middle;border-left:1px solid #e2e8f0;">
<img src="{{ $laboratorio_logo_url }}" alt="{{ $laboratorio_marca }}" width="140" style="max-width:140px;width:140px;max-height:128px;height:auto;border:0;display:block;margin:0 auto;object-fit:contain;">
<p style="margin:8px 0 0;color:#718096;font-size:12px;line-height:1.3;text-align:center;">
{{ $laboratorio_marca }}
</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
