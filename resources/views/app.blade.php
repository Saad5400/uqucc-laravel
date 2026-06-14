<!DOCTYPE html>
<html lang="ar" dir="rtl" @class(['dark' => true])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#1a1a1a">
        @php
            $siteName = config('app.name', 'دليل طالب كلية الحاسبات');
            $defaultDescription = \App\Support\Seo::DEFAULT_DESCRIPTION;

            $seo = $page['props']['seo'] ?? [];
            $currentUrl = $seo['canonical'] ?? url()->current();
            $currentPath = request()->path() === '/' ? '/' : '/' . request()->path();
            $ogImageUrl = route('og-image', ['route' => ltrim($currentPath, '/')]);

            $metaTitle = $seo['fullTitle'] ?? $siteName;
            $metaDescription = $seo['description'] ?? $defaultDescription;
            $ogTitle = $seo['title'] ?? $siteName;
            $ogType = $seo['ogType'] ?? 'website';
            $schemas = $seo['schema'] ?? [];
        @endphp

        <title inertia>{{ $metaTitle }}</title>

        {{-- Favicon --}}
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">

        {{-- Fonts --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">

        {{-- Open Graph / Facebook --}}
        <meta inertia="og:type" property="og:type" content="{{ $ogType }}">
        <meta inertia="og:title" property="og:title" content="{{ $ogTitle }}">
        <meta inertia="og:description" property="og:description" content="{{ $metaDescription }}">
        <meta inertia="og:url" property="og:url" content="{{ $currentUrl }}">
        <meta inertia="og:site_name" property="og:site_name" content="{{ $siteName }}">
        <meta inertia="og:locale" property="og:locale" content="ar_SA">
        <meta inertia="og:image" property="og:image" content="{{ $ogImageUrl }}">
        <meta inertia="og:image:width" property="og:image:width" content="720">
        <meta inertia="og:image:height" property="og:image:height" content="378">
        <meta inertia="og:image:type" property="og:image:type" content="{{ \App\Support\ScreenshotConfig::mimeType() }}">
        <meta inertia="og:image:alt" property="og:image:alt" content="{{ $ogTitle }}">

        {{-- Twitter Card --}}
        <meta inertia="twitter:card" name="twitter:card" content="summary_large_image">
        <meta inertia="twitter:title" name="twitter:title" content="{{ $ogTitle }}">
        <meta inertia="twitter:description" name="twitter:description" content="{{ $metaDescription }}">
        <meta inertia="twitter:url" name="twitter:url" content="{{ $currentUrl }}">
        <meta inertia="twitter:image" name="twitter:image" content="{{ $ogImageUrl }}">
        <meta inertia="twitter:image:alt" name="twitter:image:alt" content="{{ $ogTitle }}">

        {{-- Additional SEO Meta Tags --}}
        <meta inertia="description" name="description" content="{{ $metaDescription }}">
        <meta name="keywords" content="كلية الحاسبات, جامعة أم القرى, دليل الطالب, تخصصات الحاسب, البرمجة, علم البيانات">
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
        <meta name="author" content="{{ $siteName }}">
        <meta name="language" content="Arabic">
        <link inertia="canonical" rel="canonical" href="{{ $currentUrl }}">

        {{-- Structured Data (JSON-LD) --}}
        @foreach ($schemas as $schema)
            <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        @endforeach

        @vite(['resources/css/app.css', 'resources/css/typography.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead

        {{-- Google Analytics --}}
        @if(!config('app.debug') && config('services.google_analytics.id'))
            <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('services.google_analytics.id') }}"></script>
            <script>
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());
                gtag('config', '{{ config('services.google_analytics.id') }}');
            </script>
        @endif
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
