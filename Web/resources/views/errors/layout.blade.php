{{--
    Página de error autónoma (sin assets compilados ni rastro del framework).
    La usa el sistema cuando no puede responder con la página de la aplicación
    (mantenimiento, errores graves). Paleta institucional MR LANA.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>@yield('title') · MR LANA Asistencias</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <style>
        :root { --bg:#f4f3ef; --fg:#14201d; --muted:#5b6b66; --panel:#15332e; --gold:#8a6a35; --soft:#e4eeeb; --soft-fg:#18433d; --btn:#225c54; --btn-fg:#fff; --border:#d0cdc3; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#0e1614; --fg:#edf1ef; --muted:#a7b5b0; --panel:#0b1311; --gold:#e2c792; --soft:rgba(124,194,180,.12); --soft-fg:#9fd3c7; --btn:#2b7366; --btn-fg:#fff; --border:rgba(255,255,255,.15); }
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body { display: flex; flex-direction: column; min-height: 100vh; background: var(--bg); color: var(--fg); font-family: Montserrat, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; -webkit-font-smoothing: antialiased; }
        header { display: flex; align-items: center; height: 64px; padding: 0 16px; background: var(--panel); }
        header img { height: 28px; width: auto; }
        main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 48px 16px; }
        section { width: 100%; max-width: 28rem; text-align: center; }
        .icon { display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 8px; background: var(--soft); color: var(--soft-fg); }
        .code { margin: 24px 0 0; font-size: 11px; font-weight: 600; letter-spacing: .18em; text-transform: uppercase; color: var(--gold); }
        h1 { margin: 8px 0 0; font-size: 24px; font-weight: 600; letter-spacing: -.015em; }
        p.text { margin: 8px 0 0; font-size: 14px; line-height: 1.6; color: var(--muted); }
        .actions { margin-top: 32px; display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 16px; border-radius: 6px; font: inherit; font-size: 14px; font-weight: 500; text-decoration: none; cursor: pointer; border: 1px solid transparent; }
        .btn-primary { background: var(--btn); color: var(--btn-fg); }
        .btn-outline { background: transparent; color: var(--fg); border-color: var(--border); }
        .btn:focus-visible { outline: 3px solid var(--gold); outline-offset: 2px; }
        footer { padding: 0 16px 24px; text-align: center; font-size: 12px; color: var(--muted); }
        @media (min-width: 640px) { header { padding: 0 32px; } }
    </style>
</head>
<body>
    <header><img src="/brand/logo.svg" alt="MR LANA"></header>
    <main>
        <section aria-labelledby="error-title">
            <span class="icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
            </span>
            <p class="code">Error @yield('code')</p>
            <h1 id="error-title">@yield('title')</h1>
            <p class="text">@yield('message')</p>
            <div class="actions">
                @hasSection('reload')
                    <button type="button" class="btn btn-primary" onclick="window.location.reload()">Recargar</button>
                @else
                    <a class="btn btn-primary" href="/dashboard">Ir al panel</a>
                @endif
                <button type="button" class="btn btn-outline" onclick="history.back()">Regresar</button>
            </div>
        </section>
    </main>
    <footer>© {{ date('Y') }} MR LANA Asistencias</footer>
</body>
</html>
