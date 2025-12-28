<!DOCTYPE html>
<html lang="ar" dir="rtl" @class(['dark' => true])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- Favicon --}}
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Fonts --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">

        {{-- OG Meta Tags for Social Sharing --}}
        @php
            $currentUrl = url()->current();
            $currentPath = request()->path() === '/' ? '/' : '/' . request()->path();
            $ogImageUrl = route('og-image', ['route' => ltrim($currentPath, '/')]);
            $siteName = config('app.name', 'Laravel');
            $defaultDescription = 'دليل طالب كلية الحاسبات';
        @endphp

        {{-- Open Graph / Facebook --}}
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ $currentUrl }}">
        <meta property="og:site_name" content="{{ $siteName }}">
        <meta property="og:locale" content="ar_SA">
        <meta property="og:image" content="{{ $ogImageUrl }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:type" content="image/webp">
        <meta property="og:image:alt" content="{{ $siteName }}">

        {{-- Twitter Card --}}
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:url" content="{{ $currentUrl }}">
        <meta name="twitter:image" content="{{ $ogImageUrl }}">
        <meta name="twitter:image:alt" content="{{ $siteName }}">

        {{-- Additional SEO Meta Tags --}}
        <meta name="description" content="{{ $defaultDescription }}">
        <meta name="robots" content="index, follow">
        <meta name="author" content="{{ $siteName }}">
        <link rel="canonical" href="{{ $currentUrl }}">

        @vite(['resources/css/app.css', 'resources/css/typography.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
