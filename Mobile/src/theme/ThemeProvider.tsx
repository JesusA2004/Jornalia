import { DarkTheme, DefaultTheme, ThemeProvider as NavThemeProvider } from 'expo-router';
import { createContext, type ReactNode, useContext, useMemo } from 'react';
import { useColorScheme } from 'react-native';

import { type Colors, palette } from './tokens';

interface ThemeValue {
  scheme: 'light' | 'dark';
  colors: Colors;
}

const ThemeContext = createContext<ThemeValue>({ scheme: 'light', colors: palette.light });

export function ThemeProvider({ children }: { children: ReactNode }) {
  const system = useColorScheme();
  const scheme = system === 'dark' ? 'dark' : 'light';
  const value = useMemo<ThemeValue>(() => ({ scheme, colors: palette[scheme] }), [scheme]);
  const navTheme = useMemo(() => {
    const base = scheme === 'dark' ? DarkTheme : DefaultTheme;
    const c = palette[scheme];
    return {
      ...base,
      colors: { ...base.colors, background: c.background, card: c.surface, text: c.text, border: c.border, primary: c.accent },
    };
  }, [scheme]);
  return (
    <ThemeContext.Provider value={value}>
      <NavThemeProvider value={navTheme}>{children}</NavThemeProvider>
    </ThemeContext.Provider>
  );
}

export function useTheme(): ThemeValue {
  return useContext(ThemeContext);
}
