/**
 * Identidad oficial MR LANA (docs/identidad/GUIA_MARCA_DIGITAL.md).
 *
 * Los archivos de `public/brand/` son recursos oficiales: el logotipo se usa
 * TAL CUAL (sin recolorear, rotar, distorsionar, sombras ni contornos).
 */
export const BRAND = {
    name: 'MR LANA',
    product: 'Asistencias',
    /** Logotipo vectorial oficial (fondos claros, oscuros o degradado). */
    logoUrl: '/brand/logo.svg',
    /** Proporción ancho/alto del logotipo (viewBox 337.35 × 131.08). */
    logoRatio: 337.35 / 131.08,
    /** Ícono oficial: logotipo sobre el degradado de marca. */
    iconUrl: '/brand/icon-512.png',
    iconSmallUrl: '/brand/favicon-64.png',
    /** Personaje (uso moderado: login, estados vacíos, bienvenida). */
    mascotGreetingUrl: '/brand/personaje-saludo.webp',
    mascotPresentingUrl: '/brand/personaje-presenta.webp',
} as const;

export const APP_TITLE = `${BRAND.name} ${BRAND.product}`;
