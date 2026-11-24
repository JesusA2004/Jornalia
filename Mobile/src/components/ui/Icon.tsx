import { type AndroidSymbol, SymbolView, type SymbolViewProps } from 'expo-symbols';
import { type ColorValue, PixelRatio, Platform, type StyleProp, type ViewStyle } from 'react-native';

import { useTheme } from '@/theme/ThemeProvider';

type SFSymbol = Extract<SymbolViewProps['name'], string>;

/**
 * Iconografía nativa: SF Symbols en iOS y Material Symbols en Android
 * (expo-symbols). Nada de emojis.
 */
const ICONS = {
  home: { ios: 'house', android: 'home' },
  clock: { ios: 'clock', android: 'schedule' },
  fingerprint: { ios: 'touchid', android: 'fingerprint' },
  history: { ios: 'clock.arrow.circlepath', android: 'history' },
  requests: { ios: 'doc.text', android: 'description' },
  person: { ios: 'person.crop.circle', android: 'account_circle' },
  location: { ios: 'location', android: 'location_on' },
  locationOff: { ios: 'location.slash', android: 'location_off' },
  online: { ios: 'wifi', android: 'wifi' },
  offline: { ios: 'wifi.slash', android: 'wifi_off' },
  sync: { ios: 'arrow.triangle.2.circlepath', android: 'sync' },
  cloudDone: { ios: 'checkmark.icloud', android: 'cloud_done' },
  cloudOff: { ios: 'icloud.slash', android: 'cloud_off' },
  check: { ios: 'checkmark', android: 'check' },
  checkCircle: { ios: 'checkmark.circle.fill', android: 'check_circle' },
  warning: { ios: 'exclamationmark.triangle', android: 'warning' },
  error: { ios: 'xmark.octagon', android: 'error' },
  info: { ios: 'info.circle', android: 'info' },
  chevronRight: { ios: 'chevron.right', android: 'chevron_right' },
  bell: { ios: 'bell', android: 'notifications' },
  phone: { ios: 'iphone', android: 'smartphone' },
  seal: { ios: 'checkmark.seal', android: 'verified' },
  logout: { ios: 'rectangle.portrait.and.arrow.right', android: 'logout' },
  login: { ios: 'arrow.right.circle', android: 'login' },
  calendar: { ios: 'calendar', android: 'calendar_month' },
  vacation: { ios: 'beach.umbrella', android: 'beach_access' },
  edit: { ios: 'pencil', android: 'edit' },
  add: { ios: 'plus', android: 'add' },
  camera: { ios: 'camera', android: 'photo_camera' },
  close: { ios: 'xmark', android: 'close' },
  coffee: { ios: 'cup.and.saucer', android: 'coffee' },
  work: { ios: 'briefcase', android: 'work' },
  shield: { ios: 'lock.shield', android: 'verified_user' },
  lock: { ios: 'lock', android: 'lock' },
  receipt: { ios: 'doc.plaintext', android: 'receipt_long' },
  chart: { ios: 'chart.bar', android: 'bar_chart' },
  event: { ios: 'calendar.badge.clock', android: 'event' },
  face: { ios: 'faceid', android: 'face' },
  refresh: { ios: 'arrow.clockwise', android: 'refresh' },
  mail: { ios: 'envelope', android: 'mail' },
  eye: { ios: 'eye', android: 'visibility' },
  eyeOff: { ios: 'eye.slash', android: 'visibility_off' },
  pending: { ios: 'hourglass', android: 'hourglass_top' },
  block: { ios: 'nosign', android: 'block' },
  incidence: { ios: 'exclamationmark.bubble', android: 'report' },
  trash: { ios: 'trash', android: 'delete' },
  help: { ios: 'questionmark.circle', android: 'help' },
  arrowLeft: { ios: 'arrow.left', android: 'arrow_back' },
  arrowRight: { ios: 'arrow.right', android: 'arrow_forward' },
  zoomIn: { ios: 'plus.magnifyingglass', android: 'zoom_in' },
  zoomOut: { ios: 'minus.magnifyingglass', android: 'zoom_out' },
  signature: { ios: 'signature', android: 'signature' },
  document: { ios: 'doc.richtext', android: 'article' },
  photo: { ios: 'photo.on.rectangle', android: 'photo_library' },
  attach: { ios: 'paperclip', android: 'attach_file' },
  pause: { ios: 'pause.circle', android: 'pause_circle' },
  play: { ios: 'play.circle', android: 'play_circle' },
  stop: { ios: 'stop.circle', android: 'stop_circle' },
  megaphone: { ios: 'megaphone', android: 'campaign' },
  timer: { ios: 'timer', android: 'timer' },
  meal: { ios: 'fork.knife', android: 'restaurant' },
  store: { ios: 'building.2', android: 'store' },
  keypad: { ios: 'circle.grid.3x3', android: 'dialpad' },
  compare: { ios: 'arrow.left.arrow.right', android: 'compare_arrows' },
  inbox: { ios: 'tray', android: 'inbox' },
  shieldAlert: { ios: 'exclamationmark.shield', android: 'gpp_maybe' },
} as const satisfies Record<string, { ios: SFSymbol; android: AndroidSymbol }>;

export type IconName = keyof typeof ICONS;

export function Icon({
  name,
  size = 20,
  color,
  style,
}: {
  name: IconName;
  size?: number;
  color?: ColorValue;
  style?: StyleProp<ViewStyle>;
}) {
  const { colors } = useTheme();
  const def = ICONS[name];
  return (
    <SymbolView
      name={{ ios: def.ios, android: def.android, web: def.android }}
      // En Android el símbolo es un glifo de fuente que crece con la escala de texto
      // del sistema; se compensa para que no se recorte dentro de su caja.
      size={Platform.OS === 'android' ? size / Math.max(1, PixelRatio.getFontScale()) : size}
      tintColor={color ?? colors.text}
      style={[{ width: size, height: size }, style]}
    />
  );
}
