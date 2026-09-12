@php
    $defaultDescription = 'Cuida tu salud y la de tu familia con Famedic. Accede a atención médica, estudios de laboratorio, servicios de salud y beneficios exclusivos con cobertura nacional.';
    $defaultOgDescription = 'Encuentra atención médica, estudios de laboratorio y beneficios para cuidar tu salud y la de tu familia.';
    $defaultTwitterDescription = 'Atención médica, estudios de laboratorio y beneficios para ti y tu familia.';
    $defaultUrl = 'https://famedic.com.mx/';
    $defaultImage = 'https://famedic.com.mx/images/og/famedic-og.png';
@endphp

<meta name="description" content="{{ e($description ?? $defaultDescription) }}">

{{-- Open Graph / Facebook --}}
<meta property="og:type" content="{{ e($ogType ?? 'website') }}">
<meta property="og:site_name" content="{{ e($ogSiteName ?? 'Famedic') }}">
<meta property="og:url" content="{{ e($ogUrl ?? $defaultUrl) }}">
<meta property="og:title" content="{{ e($ogTitle ?? 'Famedic | Salud al alcance de todos') }}">
<meta property="og:description" content="{{ e($ogDescription ?? $defaultOgDescription) }}">
<meta property="og:image" content="{{ e($ogImage ?? $defaultImage) }}">
<meta property="og:image:width" content="{{ e($ogImageWidth ?? '1200') }}">
<meta property="og:image:height" content="{{ e($ogImageHeight ?? '630') }}">
<meta property="og:image:alt" content="{{ e($ogImageAlt ?? 'Famedic - Salud y tecnología a bajo costo') }}">

{{-- Twitter --}}
<meta name="twitter:card" content="{{ e($twitterCard ?? 'summary_large_image') }}">
<meta name="twitter:title" content="{{ e($twitterTitle ?? 'Famedic | Salud al alcance de todos') }}">
<meta name="twitter:description" content="{{ e($twitterDescription ?? $defaultTwitterDescription) }}">
<meta name="twitter:image" content="{{ e($twitterImage ?? $ogImage ?? $defaultImage) }}">

@if(isset($structuredData))
{{-- Structured Data --}}
<script type="application/ld+json">
@json($structuredData)
</script>
@endif
