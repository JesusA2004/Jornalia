import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import type { ReactNode } from 'react';
import type { StyleProp, ViewStyle } from 'react-native';

import { brand } from '@/theme/tokens';

const LOGO = require('../../../assets/brand/logo.png');
const LOGO_RATIO = 1200 / 467;

/** Logotipo oficial MR LANA (archivo del manual, sin modificaciones). */
export function Logo({ width = 160 }: { width?: number }) {
  return (
    <Image
      source={LOGO}
      style={{ width, height: width / LOGO_RATIO }}
      contentFit="contain"
      accessibilityRole="image"
      accessibilityLabel="Mr. Lana"
    />
  );
}

const MASCOTS = {
  saludo: { src: require('../../../assets/brand/personaje-saludo.png'), ratio: 517 / 720 },
  presenta: { src: require('../../../assets/brand/personaje-presenta.png'), ratio: 540 / 718 },
} as const;

/** Personaje de marca. Uso moderado: login, bienvenida y estados vacíos. */
export function Mascot({ pose = 'saludo', height = 140 }: { pose?: keyof typeof MASCOTS; height?: number }) {
  const m = MASCOTS[pose];
  return <Image source={m.src} style={{ height, width: height * m.ratio }} contentFit="contain" accessible={false} />;
}

/** Degradado institucional #225C54 (texto blanco). Usar con mesura: encabezados y botón de marcaje. */
export function BrandGradient({ children, style }: { children?: ReactNode; style?: StyleProp<ViewStyle> }) {
  return (
    <LinearGradient colors={brand.gradient} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={style}>
      {children}
    </LinearGradient>
  );
}
