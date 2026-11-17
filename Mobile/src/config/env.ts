import Constants from 'expo-constants';

type Extra = { apiUrl?: string; appVariant?: string; eas?: { projectId?: string } };

const extra = (Constants.expoConfig?.extra ?? {}) as Extra;

/** URL base de la API v1, sin barra final. Configurable con EXPO_PUBLIC_API_URL. */
export const API_URL: string = (process.env.EXPO_PUBLIC_API_URL ?? extra.apiUrl ?? 'http://10.0.2.2:8000/api/v1').replace(
  /\/+$/,
  '',
);

export const APP_VARIANT = extra.appVariant ?? 'production';

export const EAS_PROJECT_ID: string | undefined = extra.eas?.projectId ?? Constants.easConfig?.projectId ?? undefined;

export const APP_VERSION: string = Constants.expoConfig?.version ?? '1.0.0';
