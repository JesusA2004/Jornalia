/**
 * Tokens de diseño MR LANA Asistencias.
 * Fuente: docs/identidad/GUIA_MARCA_DIGITAL.md (manual de identidad 2026).
 *  - Verde base #A0E04E y cian base #82DBE8: fondos de acción/acento con texto NEGRO.
 *  - Tonos derivados AA para texto/íconos de color sobre blanco: #4F8A16, #137C8B.
 *  - Superficie oscura de marca #12302B.
 */
export const brand = {
  green: '#A0E04E',
  cyan: '#82DBE8',
  deep: '#12302B',
  black: '#000000',
  white: '#FFFFFF',
  gradient: ['#A0E04E', '#82DBE8'] as const,
} as const;

export const palette = {
  light: {
    background: '#F5F8F6',
    surface: '#FFFFFF',
    surfaceMuted: '#EDF2EF',
    border: '#E0E7E3',
    borderStrong: '#C6D1CB',
    text: '#0A100E',
    textMuted: '#4B5853',
    textSubtle: '#7A8781',
    primary: brand.green,
    primaryPressed: '#8CCB3A',
    onPrimary: '#000000',
    brandStrong: '#4F8A16',
    brandSoft: '#EEF8E0',
    brandInk: '#1F3B0B',
    brandDeep: brand.deep,
    /** Enlaces y acentos de texto (cian derivado AA). */
    accent: '#137C8B',
    accentSoft: '#E3F6F9',
    success: '#2F8F3A',
    successBg: '#E5F4E7',
    warning: '#B26A00',
    warningBg: '#FDF1DD',
    danger: '#C8322B',
    dangerBg: '#FCE8E6',
    info: '#137C8B',
    infoBg: '#E3F6F9',
    overlay: 'rgba(10,16,14,0.5)',
  },
  dark: {
    background: '#0D1715',
    surface: '#13201D',
    surfaceMuted: '#1A2B27',
    border: '#213530',
    borderStrong: '#2F4740',
    text: '#F2F5F3',
    textMuted: '#A8B8B1',
    textSubtle: '#6F8279',
    primary: brand.green,
    primaryPressed: '#8CCB3A',
    onPrimary: '#000000',
    brandStrong: brand.green,
    brandSoft: '#1D3317',
    brandInk: '#D9F2B8',
    brandDeep: brand.deep,
    accent: brand.cyan,
    accentSoft: '#10292E',
    success: '#5CC46A',
    successBg: '#12291A',
    warning: '#F0B35A',
    warningBg: '#2B210F',
    danger: '#F07A70',
    dangerBg: '#2E1614',
    info: brand.cyan,
    infoBg: '#10292E',
    overlay: 'rgba(0,0,0,0.6)',
  },
} as const;

export type ColorName = keyof typeof palette.light;
export type Colors = { [K in ColorName]: string };

export const spacing = { xxs: 2, xs: 4, sm: 8, md: 12, lg: 16, xl: 20, xxl: 24, xxxl: 32, huge: 48 } as const;

export const radius = { sm: 10, md: 14, lg: 18, xl: 24, pill: 999 } as const;

/** Altura mínima de objetivos táctiles (Material: 48 dp). */
export const TOUCH_MIN = 48;

/**
 * Tipografías secundarias oficiales (OFL): Montserrat (texto) y Baloo 2
 * (títulos y cifras). La corporativa Museo Sans Rounded requiere licencia.
 */
export const fonts = {
  regular: 'Montserrat_400Regular',
  medium: 'Montserrat_500Medium',
  semibold: 'Montserrat_600SemiBold',
  bold: 'Montserrat_700Bold',
  display: 'Baloo2_700Bold',
  displayHeavy: 'Baloo2_800ExtraBold',
  displayMedium: 'Baloo2_600SemiBold',
} as const;

export const typography = {
  display: { fontFamily: fonts.displayHeavy, fontSize: 60, lineHeight: 70, letterSpacing: -0.5 },
  title: { fontFamily: fonts.display, fontSize: 28, lineHeight: 34 },
  heading: { fontFamily: fonts.displayMedium, fontSize: 20, lineHeight: 26 },
  body: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22 },
  bodyStrong: { fontFamily: fonts.semibold, fontSize: 15, lineHeight: 22 },
  caption: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 19 },
  captionStrong: { fontFamily: fonts.semibold, fontSize: 13, lineHeight: 19 },
  label: { fontFamily: fonts.bold, fontSize: 11.5, lineHeight: 16, letterSpacing: 0.9, textTransform: 'uppercase' as const },
  metric: { fontFamily: fonts.display, fontSize: 24, lineHeight: 30 },
} as const;

export type TextVariant = keyof typeof typography;
