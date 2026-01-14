<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <link rel="manifest" href="/manifest.json">
    <!-- ios support -->
    <link rel="apple-touch-icon" href="images/icons/ios/16.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/20.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/29.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/32.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/40.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/50.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/57.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/58.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/60.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/72.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/76.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/80.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/87.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/100.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/114.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/120.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/128.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/144.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/152.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/167.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/180.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/192.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/256.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/512.png" />
    <link rel="apple-touch-icon" href="images/icons/ios/1024.png" />
    <meta name="apple-mobile-web-app-status-bar" content="#020617" />
    <meta name="theme-color" content="#020617" />
    {{-- @env('local')
    <script src="http://localhost:8097"></script>
    @endenv --}}
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'Laravel') }}</title>

    @include('partials.meta-tags', [
        'ogType' => $page['props']['og_type'] ?? null,
        'ogUrl' => $page['props']['og_url'] ?? null,
        'ogTitle' => $page['props']['og_title'] ?? null,
        'ogDescription' => $page['props']['og_description'] ?? null,
        'ogImage' => $page['props']['og_image'] ?? null,
        'twitterCard' => $page['props']['twitter_card'] ?? null,
        'twitterUrl' => $page['props']['twitter_url'] ?? null,
        'twitterTitle' => $page['props']['twitter_title'] ?? null,
        'twitterDescription' => $page['props']['twitter_description'] ?? null,
        'twitterImage' => $page['props']['twitter_image'] ?? null,
        'structuredData' => $page['props']['structured_data'] ?? null,
    ])

    <!-- Fonts -->
    <link rel="preconnect" href="https://rsms.me/" />
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">

    <!-- Scripts -->
    @routes
    @viteReactRefresh
    @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
    @inertiaHead

    @env('production')
    <script src="https://cdn.usefathom.com/script.js" data-spa="auto" data-site="CURLVLNC" defer></script>

    <!--  Hotjar Tracking Code for https://famedic.com.mx -->
    <script>
    (function(h,o,t,j,a,r){
        h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};
        h._hjSettings={hjid:6467565,hjsv:6};
        a=o.getElementsByTagName('head')[0];
        r=o.createElement('script');r.async=1;
        r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;
        a.appendChild(r);
    })(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');
    </script>

    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','GTM-T39QLNX6');</script>
    <!-- End Google Tag Manager -->

    @endenv
</head>

<body class="font-sans antialiased">
    @inertia

    @env('production')
    <!-- Facebook Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window,document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
     fbq('init', '1818127705804364'); 
    fbq('track', 'PageView');
    </script>
    <noscript>
     <img height="1" width="1" 
    src="https://www.facebook.com/tr?id=1818127705804364&ev=PageView
    &noscript=1"/>
    </noscript>
    <!-- End Facebook Pixel Code -->
    
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-F5VNYJNMBP"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-F5VNYJNMBP');
    </script>
    <!-- End Google tag (gtag.js) -->
    @endenv
</body>

</html>