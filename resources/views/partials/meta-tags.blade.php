{{-- Open Graph / Facebook --}}
<meta property="og:type" content="{{ e($ogType ?? 'website') }}">
<meta property="og:url" content="{{ e($ogUrl ?? url()->current()) }}">
<meta property="og:title" content="{{ e($ogTitle ?? config('app.name', 'Famedic')) }}">
<meta property="og:description" content="{{ e($ogDescription ?? 'Tu salud, a un clic de distancia. Laboratorios, farmacia y atención médica en línea.') }}">
<meta property="og:image" content="{{ e($ogImage ?? asset('images/logo.png')) }}">

{{-- Twitter --}}
<meta property="twitter:card" content="{{ e($twitterCard ?? 'summary_large_image') }}">
<meta property="twitter:url" content="{{ e($twitterUrl ?? $ogUrl ?? url()->current()) }}">
<meta property="twitter:title" content="{{ e($twitterTitle ?? $ogTitle ?? config('app.name', 'Famedic')) }}">
<meta property="twitter:description" content="{{ e($twitterDescription ?? $ogDescription ?? 'Tu salud, a un clic de distancia. Laboratorios, farmacia y atención médica en línea.') }}">
<meta property="twitter:image" content="{{ e($twitterImage ?? $ogImage ?? asset('images/logo.png')) }}">

@if(isset($structuredData))
{{-- Structured Data --}}
<script type="application/ld+json">
@json($structuredData)
</script>
@endif