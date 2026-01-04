<!DOCTYPE html>
<html lang="ar" dir="rtl" @class(['dark' => true])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#1a1a1a">

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
            $siteName = config('app.name', 'دليل طالب كلية الحاسبات');
            $defaultDescription = 'دليلك الشامل لكل ما يخص كلية الحاسبات، من تخصصات، نصائح، وأدوات لمساعدتك في رحلتك الأكاديمية.';
        @endphp

        {{-- Open Graph / Facebook --}}
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ $currentUrl }}">
        <meta property="og:site_name" content="{{ $siteName }}">
        <meta property="og:locale" content="ar_SA">
        <meta property="og:image" content="{{ $ogImageUrl }}">
        <meta property="og:image:width" content="720">
        <meta property="og:image:height" content="378">
        <meta property="og:image:type" content="image/webp">
        <meta property="og:image:alt" content="{{ $siteName }}">

        {{-- Twitter Card --}}
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:url" content="{{ $currentUrl }}">
        <meta name="twitter:image" content="{{ $ogImageUrl }}">
        <meta name="twitter:image:alt" content="{{ $siteName }}">

        {{-- Additional SEO Meta Tags --}}
        <meta name="description" content="{{ $defaultDescription }}">
        <meta name="keywords" content="كلية الحاسبات, جامعة أم القرى, دليل الطالب, تخصصات الحاسب, البرمجة, علم البيانات">
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
        <meta name="author" content="{{ $siteName }}">
        <meta name="language" content="Arabic">
        <link rel="canonical" href="{{ $currentUrl }}">

        {{-- Structured Data (JSON-LD) for SEO --}}
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "EducationalOrganization",
            "name": "{{ $siteName }}",
            "description": "{{ $defaultDescription }}",
            "url": "{{ url('/') }}",
            "logo": "{{ asset('favicon.svg') }}",
            "inLanguage": "ar",
            "address": {
                "@@type": "PostalAddress",
                "addressCountry": "SA",
                "addressLocality": "Makkah"
            }
        }
        </script>
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "WebSite",
            "name": "{{ $siteName }}",
            "url": "{{ url('/') }}",
            "potentialAction": {
                "@@type": "SearchAction",
                "target": "{{ url('/') }}?q={search_term_string}",
                "query-input": "required name=search_term_string"
            }
        }
        </script>

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
