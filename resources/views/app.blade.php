<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-background">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'VantageJobs') }}</title>

        @php($cspNonce = request()->attributes->get('csp_nonce'))
        @routes(nonce: $cspNonce)
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="h-full bg-background font-sans antialiased">
        @inertia
    </body>
</html>
