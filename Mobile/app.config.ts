import fs from 'node:fs';
import path from 'node:path';

import type { ConfigContext, ExpoConfig } from 'expo/config';

/**
 * Configuración de la app MR LANA Asistencias.
 *
 * Variables de entorno (ver README):
 *  - APP_VARIANT          development | preview | production (por defecto production)
 *  - ANDROID_PACKAGE      paquete Android / bundle iOS (por defecto mx.mrlana.asistencias)
 *  - EXPO_PUBLIC_API_URL  URL base de la API v1 (por defecto http://10.0.2.2:8000/api/v1)
 *  - EAS_PROJECT_ID       projectId de EAS (necesario para tokens push de Expo)
 */
const variant = (process.env.APP_VARIANT ?? 'production') as 'development' | 'preview' | 'production';
const basePackage = process.env.ANDROID_PACKAGE ?? 'mx.mrlana.asistencias';
const packageId = variant === 'production' ? basePackage : `${basePackage}.${variant === 'development' ? 'dev' : 'preview'}`;
const appName =
  variant === 'production' ? 'MR LANA Asistencias' : `MR LANA Asistencias (${variant === 'development' ? 'Dev' : 'Preview'})`;
const easProjectId = process.env.EAS_PROJECT_ID;

/**
 * Firebase (FCM HTTP v1), igual que la app de Auditoría: se usa
 * `GOOGLE_SERVICES_JSON` (ruta, p. ej. secreto de archivo en EAS) o
 * `./google-services.json` si existe. Sin archivo, el build no incluye Firebase
 * y la app no registra token push (lo indica sin fallar).
 */
const localGoogleServices = path.join(__dirname, 'google-services.json');
const googleServicesFile =
  process.env.GOOGLE_SERVICES_JSON && fs.existsSync(process.env.GOOGLE_SERVICES_JSON)
    ? process.env.GOOGLE_SERVICES_JSON
    : fs.existsSync(localGoogleServices)
      ? './google-services.json'
      : undefined;

/** Superficie oscura de marca (docs/identidad/GUIA_MARCA_DIGITAL.md). */
const BRAND_DEEP = '#12302B';

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: appName,
  slug: process.env.APP_SLUG ?? 'mr-lana-asistencias',
  version: '1.0.0',
  orientation: 'portrait',
  icon: './assets/brand/icon.png',
  scheme: 'mrlanaasistencias',
  userInterfaceStyle: 'automatic',
  ios: {
    bundleIdentifier: packageId,
    icon: './assets/brand/icon.png',
    supportsTablet: false,
    config: { usesNonExemptEncryption: false },
  },
  android: {
    package: packageId,
    versionCode: 1,
    ...(googleServicesFile ? { googleServicesFile } : {}),
    adaptiveIcon: {
      backgroundColor: '#A0E04E',
      foregroundImage: './assets/brand/adaptive-foreground.png',
      backgroundImage: './assets/brand/adaptive-background.png',
      monochromeImage: './assets/brand/adaptive-monochrome.png',
    },
    predictiveBackGestureEnabled: false,
    allowBackup: false,
    // Permisos mínimos. Los módulos agregan los suyos; aquí se declaran los
    // necesarios y se bloquean explícitamente los que no se usan.
    permissions: [
      'android.permission.CAMERA',
      'android.permission.ACCESS_COARSE_LOCATION',
      'android.permission.ACCESS_FINE_LOCATION',
      'android.permission.USE_BIOMETRIC',
      'android.permission.POST_NOTIFICATIONS',
      'android.permission.ACCESS_BACKGROUND_LOCATION',
      'android.permission.FOREGROUND_SERVICE',
      'android.permission.FOREGROUND_SERVICE_LOCATION',
    ],
    blockedPermissions: [
      'android.permission.RECORD_AUDIO',
      'android.permission.READ_EXTERNAL_STORAGE',
      'android.permission.WRITE_EXTERNAL_STORAGE',
      'android.permission.READ_MEDIA_IMAGES',
      'android.permission.READ_MEDIA_VIDEO',
      'android.permission.READ_MEDIA_AUDIO',
      'android.permission.SYSTEM_ALERT_WINDOW',
      'android.permission.READ_PHONE_STATE',
    ],
  },
  web: {
    output: 'static',
    favicon: './assets/brand/icon.png',
  },
  plugins: [
    'expo-router',
    [
      'expo-splash-screen',
      {
        backgroundColor: BRAND_DEEP,
        image: './assets/brand/logo.png',
        imageWidth: 220,
      },
    ],
    [
      'expo-secure-store',
      {
        configureAndroidBackup: true,
        faceIDPermission: 'MR LANA Asistencias usa Face ID para proteger la credencial con la que se firman tus marcajes.',
      },
    ],
    [
      'expo-local-authentication',
      {
        faceIDPermission: 'MR LANA Asistencias usa Face ID para confirmar que eres tú antes de registrar un marcaje.',
      },
    ],
    [
      'expo-location',
      {
        locationWhenInUsePermission:
          'MR LANA Asistencias usa tu ubicación al marcar y para compartir tu ubicación de trabajo durante tu turno.',
        locationAlwaysAndWhenInUsePermission:
          'MR LANA Asistencias comparte tu ubicación de trabajo sólo durante tu turno, también con la pantalla apagada, con el aviso «Ubicación de trabajo activa» visible. Se detiene al cerrar sesión.',
        isAndroidBackgroundLocationEnabled: true,
        isAndroidForegroundServiceEnabled: true,
        isIosBackgroundLocationEnabled: true,
      },
    ],
    [
      'expo-camera',
      {
        cameraPermission: 'MR LANA Asistencias usa la cámara para adjuntar evidencia a tus solicitudes.',
        recordAudioAndroid: false,
        barcodeScannerEnabled: false,
      },
    ],
    [
      'expo-notifications',
      {
        icon: './assets/brand/notification-icon.png',
        color: '#A0E04E',
        defaultChannel: 'default',
      },
    ],
    ['expo-sqlite', { useSQLCipher: true }],
    'expo-background-task',
    '@react-native-community/datetimepicker',
    'expo-font',
    './plugins/with-cmake-object-path-max',
  ],
  experiments: {
    typedRoutes: true,
    reactCompiler: true,
  },
  extra: {
    appVariant: variant,
    apiUrl: process.env.EXPO_PUBLIC_API_URL ?? 'http://10.0.2.2:8000/api/v1',
    ...(easProjectId ? { eas: { projectId: easProjectId } } : {}),
  },
});
