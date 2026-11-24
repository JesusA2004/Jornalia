<!DOCTYPE html>
<html lang="es" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="theme-color" content="#15332E" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0B1311" media="(prefers-color-scheme: dark)">
        <meta name="application-name" content="{{ config('app.name', 'MR LANA Asistencias') }}">
        <meta name="robots" content="noindex, nofollow">

        {{-- Aplica el tema oscuro antes del primer pintado para evitar parpadeo --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Fondo del documento sincronizado con --background de resources/css/app.css --}}
        <style>
            html {
                background-color: #f4f3ef;
            }

            html.dark {
                background-color: #0e1614;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/brand/favicon-64.png" type="image/png" sizes="64x64">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'MR LANA Asistencias') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
