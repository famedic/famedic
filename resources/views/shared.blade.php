<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title inertia>{{ $page['props']['meta_title'] ?? config('app.name', 'Famedic Mexico') }}</title>

    @include('partials.meta-tags', [
        'description' => $page['props']['description'] ?? null,
        'ogType' => $page['props']['og_type'] ?? null,
        'ogSiteName' => $page['props']['og_site_name'] ?? null,
        'ogUrl' => $page['props']['og_url'] ?? null,
        'ogTitle' => $page['props']['og_title'] ?? null,
        'ogDescription' => $page['props']['og_description'] ?? null,
        'ogImage' => $page['props']['og_image'] ?? null,
        'ogImageWidth' => $page['props']['og_image_width'] ?? null,
        'ogImageHeight' => $page['props']['og_image_height'] ?? null,
        'ogImageAlt' => $page['props']['og_image_alt'] ?? null,
        'twitterCard' => $page['props']['twitter_card'] ?? null,
        'twitterUrl' => $page['props']['twitter_url'] ?? null,
        'twitterTitle' => $page['props']['twitter_title'] ?? null,
        'twitterDescription' => $page['props']['twitter_description'] ?? null,
        'twitterImage' => $page['props']['twitter_image'] ?? null,
        'structuredData' => $page['props']['structured_data'] ?? null,
    ])

    @routes
    @viteReactRefresh
    @vite(['resources/js/app.jsx'])
    @inertiaHead
</head>

<body class="font-sans antialiased">
    @inertia
</body>

</html>
