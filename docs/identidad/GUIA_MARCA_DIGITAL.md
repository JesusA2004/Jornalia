# Guía de marca digital — MR LANA Asistencias

Fuente: `docs/identidad/MANUAL DE IDENTIDAD  MR LANA 2026 (1).pdf` (sección 5, colores; 4, tipografía; 6–8, versiones y usos).

## Colores oficiales (manual, sección 5)

| Rol | HEX | Uso |
|---|---|---|
| Verde base | `#A0E04E` | Color principal de marca, inicio del degradado |
| Cian base | `#82DBE8` | Color secundario, fin del degradado |
| Blanco base | `#FFFFFF` | Fondos y superficies |
| Negro textos | `#000000` | Textos y bloque del imagotipo |
| Punto del logotipo | `#3ABBEA` | Sólo el punto de "Mr.lana" (vectorial del manual) |
| Degradado | `#A0E04E → #82DBE8` | Bandas, encabezados de marca, ícono de app |

Fondos de portadas del manual: verde/teal profundo (`#163A36` → `#24461F` aprox.), usado como superficie oscura de marca.

## Tonos derivados para interfaz (accesibilidad AA)

Los colores base son claros: **sobre ellos el texto va en negro** (verde `#A0E04E` con negro ≈ 13:1). Para texto
o íconos de color sobre blanco se usan tonos derivados más oscuros, de la misma familia:

| Token | HEX | Contraste sobre blanco | Uso |
|---|---|---|---|
| `brand` | `#A0E04E` | (fondo, texto negro) | Botón primario, estados activos, selección |
| `brand-strong` | `#4F8A16` | 4.6:1 | Texto/ícono verde sobre blanco, enlaces |
| `brand-ink` | `#1F3B0B` | 12:1 | Texto sobre `brand-soft` |
| `brand-soft` | `#EEF8E0` | — | Fondos suaves de selección |
| `accent` (cian) | `#82DBE8` | (fondo, texto negro) | Información, acentos secundarios |
| `accent-strong` | `#137C8B` | 4.8:1 | Texto/ícono cian sobre blanco |
| `brand-deep` | `#12302B` | — | Barra lateral / paneles oscuros de marca (texto blanco) |
| Estados | éxito `#2F8F3A`, advertencia `#B26A00`, error `#C8322B`, info `#137C8B` | ≥ 4.5:1 | Distinguibles del verde de marca |

Modo oscuro: superficie `#0D1715`, tarjetas `#13201D`, verde y cian base se mantienen como acentos
(contraste alto sobre fondo oscuro), texto `#F2F5F3`.

## Tipografía (manual, sección 4)

- Corporativa: **Museo Sans Rounded** (licencia comercial; no incluida). Si la empresa provee la licencia web/app,
  colocar los archivos en `Web/public/fonts/` y `Mobile/assets/fonts/` y activarla en los tokens.
- Secundarias oficiales (libres, OFL): **Montserrat** (texto de interfaz) y **Baloo 2** (títulos y cifras destacadas).
- Las cifras de horas usan numerales tabulares (`font-variant-numeric: tabular-nums`).

## Logotipo

- Archivo vectorial: `docs/identidad/recursos/mrlana-logo.svg` (extraído del manual, sin modificaciones), copias en
  `Web/public/brand/logo.svg` y `Mobile/assets/brand/logo.svg`.
- Prohibido (manual, sección 8): cambiar tipografía, color, rotar, distorsionar, alterar elementos, agregar sombras o contornos.
- Respetar zona de protección y tamaño mínimo digital; sobre fondos claros, oscuros o el degradado de marca se usa tal cual.
- Ícono de app y favicon: logotipo oficial centrado sobre el degradado oficial (`Mobile/assets/brand/icon.png`).

## Personaje

`mrlana-personaje-saludo.png` y `mrlana-personaje-presenta.png` (recortados del manual, fondo transparente). Uso
moderado: pantalla de inicio de sesión, estados vacíos y bienvenida. No usar como ícono funcional.
