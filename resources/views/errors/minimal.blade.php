<!DOCTYPE html>
<html lang="ar" dir="rtl" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1a1a1a">

    <title>@yield('title') - {{ config('app.name', 'Laravel') }}</title>

    {{-- Favicon --}}
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css'])
</head>
<body class="antialiased">
    <div class="min-h-screen flex items-center justify-center p-4 bg-background text-foreground">
        <div class="w-full max-w-md">
            <div class="p-8 border rounded-lg shadow-sm bg-sidebar border-sidebar-border text-center space-y-6">
                {{-- Error Code --}}
                <div class="space-y-2">
                    <h1 class="text-6xl font-bold text-primary">@yield('code')</h1>
                    <h2 class="text-2xl font-semibold text-sidebar-foreground">@yield('title')</h2>
                </div>

                {{-- Error Message --}}
                <p class="text-muted-foreground leading-relaxed">
                    @yield('message')
                </p>

                {{-- Go to Home Button --}}
                <div class="pt-4">
                    <a href="{{ url('/') }}"
                       class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                        العودة إلى الصفحة الرئيسية
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
