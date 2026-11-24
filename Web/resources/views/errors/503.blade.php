{{--
    Pantalla de mantenimiento (503) de MR LANA Asistencias. Se muestra con
    `php artisan down` en la web. Es autocontenida: sin Vite, sin fuentes externas
    ni JavaScript de la app, para que funcione aunque el build esté a medio
    desplegar. Recomendado: `php artisan down --render="errors::503" --retry=60`
    (pre-renderiza esta vista y no toca la base de datos mientras dure).
    La API conserva su respuesta JSON 503 (ver bootstrap/app.php): la app móvil
    guarda los marcajes sin conexión y los envía al terminar el mantenimiento.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0D3E43">
    <meta http-equiv="refresh" content="60">
    <title>En mantenimiento · MR LANA Asistencias</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <style>
        :root {
            --petroleo: #0d3e43;
            --petroleo-medio: #225c54;
            --dorado: #af8b51;
            --dorado-claro: #e9d6b0;
            --crema: #f7f3ea;
            --texto-suave: #cfe0dc;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--crema);
            background: radial-gradient(120% 90% at 50% 0%, var(--petroleo-medio) 0%, var(--petroleo) 55%, #082a2e 100%);
            display: grid;
            place-items: center;
            overflow: hidden;
            padding: max(24px, env(safe-area-inset-top)) max(20px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(20px, env(safe-area-inset-left));
        }
        .fondo { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; }
        .burbuja {
            position: absolute; bottom: -120px; border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(233, 214, 176, .35), rgba(175, 139, 81, .08));
            animation: subir linear infinite;
        }
        .burbuja:nth-child(1) { left: 8%;  width: 70px;  height: 70px;  animation-duration: 16s; }
        .burbuja:nth-child(2) { left: 26%; width: 34px;  height: 34px;  animation-duration: 11s; animation-delay: -4s; }
        .burbuja:nth-child(3) { left: 48%; width: 110px; height: 110px; animation-duration: 21s; animation-delay: -9s; }
        .burbuja:nth-child(4) { left: 68%; width: 46px;  height: 46px;  animation-duration: 13s; animation-delay: -2s; }
        .burbuja:nth-child(5) { left: 84%; width: 82px;  height: 82px;  animation-duration: 18s; animation-delay: -12s; }
        @keyframes subir {
            0%   { transform: translateY(0) scale(.9); opacity: 0; }
            10%  { opacity: 1; }
            100% { transform: translateY(-120vh) scale(1.1); opacity: 0; }
        }

        main {
            position: relative; z-index: 1; width: 100%; max-width: 40rem; text-align: center;
            display: grid; gap: 1.1rem; justify-items: center;
            animation: aparecer .8s cubic-bezier(.2, .8, .2, 1) both;
        }
        @keyframes aparecer { from { transform: translateY(18px) scale(.98); } to { transform: none; } }

        .logo { height: clamp(34px, 8vw, 46px); width: auto; }

        .escena { position: relative; width: min(70vw, 270px); height: min(70vw, 270px); display: grid; place-items: center; }
        .halo {
            position: absolute; inset: 0; border-radius: 50%;
            border: 2px solid rgba(233, 214, 176, .25);
            animation: halo 2.8s ease-out infinite;
        }
        .halo.dos { animation-delay: 1.4s; }
        @keyframes halo { from { transform: scale(.7); opacity: .9; } to { transform: scale(1.35); opacity: 0; } }
        .disco {
            width: 72%; height: 72%; border-radius: 50%;
            background: var(--crema);
            box-shadow: 0 18px 40px rgba(0, 0, 0, .35), inset 0 0 0 4px rgba(175, 139, 81, .35);
            display: grid; place-items: center;
            animation: flotar 4s ease-in-out infinite;
        }
        @keyframes flotar { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
        .reloj { width: 78%; height: auto; overflow: visible; }
        /* Manecillas del reloj checador: el minutero da una vuelta cada 6 s y el horario cada 72 s. */
        .minutero { transform-origin: 50px 50px; animation: girar 6s linear infinite; }
        .horario { transform-origin: 50px 50px; animation: girar 72s linear infinite; }
        .engrane {
            position: absolute; right: 0; bottom: 4%; width: 26%; height: 26%;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, .35));
            animation: girar 6s linear infinite;
        }
        .engrane.chico { right: auto; left: 4%; bottom: auto; top: 8%; width: 17%; height: 17%; animation-direction: reverse; animation-duration: 4s; }
        @keyframes girar { to { transform: rotate(360deg); } }

        .etiqueta {
            font-size: .72rem; letter-spacing: .18em; text-transform: uppercase; font-weight: 700;
            color: var(--dorado-claro); background: rgba(175, 139, 81, .18); border: 1px solid rgba(233, 214, 176, .25);
            padding: .35rem .8rem; border-radius: 999px;
        }
        h1 { margin: 0; font-size: clamp(2rem, 7vw, 3.2rem); line-height: 1.15; font-weight: 800; text-wrap: balance; }
        p { margin: 0; color: var(--texto-suave); line-height: 1.6; max-width: 32rem; font-size: clamp(1rem, 3.6vw, 1.2rem); }

        .barra { width: min(100%, 24rem); height: 8px; border-radius: 999px; background: rgba(255, 255, 255, .12); overflow: hidden; }
        .barra span {
            display: block; height: 100%; width: 40%; border-radius: inherit;
            background: linear-gradient(90deg, var(--dorado), var(--dorado-claro));
            animation: cargar 1.8s ease-in-out infinite;
        }
        @keyframes cargar { 0% { transform: translateX(-110%); } 100% { transform: translateX(260%); } }

        .boton {
            margin-top: .3rem; appearance: none; border: 0; cursor: pointer;
            font: inherit; font-weight: 700; color: var(--petroleo);
            background: linear-gradient(180deg, var(--dorado-claro), #d8be8b);
            padding: 1rem 2.2rem; border-radius: 999px; min-height: 52px; font-size: 1.05rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .25);
            transition: transform .15s ease, box-shadow .15s ease;
            text-decoration: none; display: inline-block;
        }
        .boton:hover { transform: translateY(-2px); box-shadow: 0 12px 26px rgba(0, 0, 0, .3); }
        .boton:active { transform: translateY(0) scale(.98); }
        .boton:focus-visible { outline: 3px solid var(--dorado-claro); outline-offset: 3px; }
        .nota { font-size: .8rem; color: rgba(207, 224, 220, .75); }

        /* Laptops y teléfonos bajos: la escena se encoge para que todo quepa sin desplazamiento. */
        @media (max-height: 820px) {
            .escena { width: min(32vh, 220px); height: min(32vh, 220px); }
            main { gap: .75rem; }
            h1 { font-size: clamp(1.8rem, 5vw, 2.6rem); }
        }
        @media (max-height: 560px) {
            .escena { display: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
            .burbuja { display: none; }
        }
    </style>
</head>
<body>
    <div class="fondo" aria-hidden="true">
        <span class="burbuja"></span><span class="burbuja"></span><span class="burbuja"></span><span class="burbuja"></span><span class="burbuja"></span>
    </div>

    <main>
        <img class="logo" src="/brand/logo.svg" alt="Mr. Lana">

        <div class="escena" aria-hidden="true">
            <span class="halo"></span><span class="halo dos"></span>
            <div class="disco">
                {{-- Reloj checador con los colores institucionales (petróleo y dorado). --}}
                <svg class="reloj" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <linearGradient id="oro" x1="0" y1="1" x2="1" y2="0">
                            <stop offset="0" stop-color="#754711"/><stop offset=".55" stop-color="#AF8B51"/><stop offset="1" stop-color="#C9A86C"/>
                        </linearGradient>
                    </defs>
                    <circle cx="50" cy="50" r="40" fill="#fff" stroke="#225C54" stroke-width="6"/>
                    <g stroke="#225C54" stroke-width="3" stroke-linecap="round">
                        <line x1="50" y1="15" x2="50" y2="22"/><line x1="85" y1="50" x2="78" y2="50"/>
                        <line x1="50" y1="85" x2="50" y2="78"/><line x1="15" y1="50" x2="22" y2="50"/>
                    </g>
                    <g stroke="#AF8B51" stroke-width="1.8" stroke-linecap="round" opacity=".8">
                        <line x1="67.5" y1="19.7" x2="65.5" y2="23.2"/><line x1="80.3" y1="32.5" x2="76.8" y2="34.5"/>
                        <line x1="80.3" y1="67.5" x2="76.8" y2="65.5"/><line x1="67.5" y1="80.3" x2="65.5" y2="76.8"/>
                        <line x1="32.5" y1="80.3" x2="34.5" y2="76.8"/><line x1="19.7" y1="67.5" x2="23.2" y2="65.5"/>
                        <line x1="19.7" y1="32.5" x2="23.2" y2="34.5"/><line x1="32.5" y1="19.7" x2="34.5" y2="23.2"/>
                    </g>
                    <line class="horario" x1="50" y1="50" x2="50" y2="31" stroke="#225C54" stroke-width="5" stroke-linecap="round"/>
                    <line class="minutero" x1="50" y1="50" x2="50" y2="21" stroke="url(#oro)" stroke-width="3.5" stroke-linecap="round"/>
                    <circle cx="50" cy="50" r="4.5" fill="#225C54"/><circle cx="50" cy="50" r="2" fill="#E9D6B0"/>
                </svg>
            </div>
            <svg class="engrane" viewBox="0 0 24 24"><path fill="#E9D6B0" d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.3 7.3 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.3 7.3 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7.3 7.3 0 0 0 1.7 1l.4 2.6h4l.4-2.6a7.3 7.3 0 0 0 1.7-1l2.5 1 2-3.5zM13 15.5A3.5 3.5 0 1 1 13 8.5a3.5 3.5 0 0 1 0 7z" transform="translate(-1 0)"/></svg>
            <svg class="engrane chico" viewBox="0 0 24 24"><path fill="#AF8B51" d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.3 7.3 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.3 7.3 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7.3 7.3 0 0 0 1.7 1l.4 2.6h4l.4-2.6a7.3 7.3 0 0 0 1.7-1l2.5 1 2-3.5zM13 15.5A3.5 3.5 0 1 1 13 8.5a3.5 3.5 0 0 1 0 7z" transform="translate(-1 0)"/></svg>
        </div>

        <span class="etiqueta">MR LANA Asistencias</span>
        <h1>Estamos mejorando el sistema</h1>
        <p>Estamos aplicando una actualización para que todo funcione mejor. Vuelve en unos minutos; tus registros de asistencia están seguros.</p>
        <div class="barra" aria-hidden="true"><span></span></div>
        <a class="boton" href="{{ url()->current() }}">Volver a intentar</a>
        <p class="nota">Esta página se actualiza sola cada minuto.</p>
    </main>
</body>
</html>
