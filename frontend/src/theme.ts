/**
 * SEDERG_PAY — Registro de temas visuales de la plataforma.
 *
 * Dos familias:
 *  - `sederge`  · identidad "Dos orillas" (azul institucional)
 *  - `aurora`   · estilo "glass" inspirado en la estética de 9Drive
 *                 (fondo con degradados suaves + tarjetas translúcidas)
 *
 * Cada familia tiene variante clara y oscura. El tema global lo define el
 * módulo de administración (`appearance.theme`) y se aplica a toda la
 * plataforma; el conmutador de la cabecera guarda una preferencia personal.
 */

export type ThemeId = 'light' | 'dark' | 'aurora' | 'aurora-dark';
export type ThemeFamily = 'sederge' | 'aurora';
export type ThemeMode = 'light' | 'dark';

export type ThemeDefinition = {
  id: ThemeId;
  family: ThemeFamily;
  mode: ThemeMode;
  labelKey: string;
  taglineKey: string;
  /** Colores representativos para la vista previa del selector. */
  swatches: [string, string, string];
};

export const THEME_STORAGE_KEY = 'sederg_theme';
export const THEME_OVERRIDE_KEY = 'sederg_theme_override';
export const PLATFORM_THEME_KEY = 'sederg_platform_theme';
export const THEME_EVENT = 'sederg:theme-change';
export const THEME_SETTING_KEY = 'appearance.theme';

export const THEMES: ThemeDefinition[] = [
  {
    id: 'light',
    family: 'sederge',
    mode: 'light',
    labelKey: 'theme.option.light',
    taglineKey: 'theme.tagline.light',
    swatches: ['#0B4F9C', '#E7F2FC', '#F4F6F9'],
  },
  {
    id: 'dark',
    family: 'sederge',
    mode: 'dark',
    labelKey: 'theme.option.dark',
    taglineKey: 'theme.tagline.dark',
    swatches: ['#8FA8FF', '#1B2540', '#0B1220'],
  },
  {
    id: 'aurora',
    family: 'aurora',
    mode: 'light',
    labelKey: 'theme.option.aurora',
    taglineKey: 'theme.tagline.aurora',
    swatches: ['#3B82F6', '#E0E7FF', '#FAFCFD'],
  },
  {
    id: 'aurora-dark',
    family: 'aurora',
    mode: 'dark',
    labelKey: 'theme.option.auroraDark',
    taglineKey: 'theme.tagline.auroraDark',
    swatches: ['#818CF8', '#1E293B', '#090D16'],
  },
];

const THEME_IDS = THEMES.map((theme) => theme.id);

export function isThemeId(value: unknown): value is ThemeId {
  return typeof value === 'string' && (THEME_IDS as string[]).includes(value);
}

/** Acepta ids válidos y valores heredados; devuelve `null` si no reconoce nada. */
export function normalizeTheme(value: unknown): ThemeId | null {
  if (typeof value !== 'string') return null;
  const clean = value.trim().toLowerCase();
  if (clean === 'system' || clean === 'auto') return systemTheme();
  if ((clean === 'light' || clean === 'dark') && isThemeId(clean)) return clean;
  return isThemeId(clean) ? clean : null;
}

export function themeById(id: ThemeId): ThemeDefinition {
  return THEMES.find((theme) => theme.id === id) ?? THEMES[0];
}

export function systemTheme(): ThemeId {
  const prefersDark = typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches;
  return prefersDark ? 'dark' : 'light';
}

export function getStoredOverride(): ThemeId | null {
  try {
    return normalizeTheme(localStorage.getItem(THEME_OVERRIDE_KEY));
  } catch {
    return null;
  }
}

/**
 * Tema con el que se pinta la app antes de hablar con la API:
 * preferencia personal → tema global cacheado → preferencia del sistema.
 */
export function getInitialTheme(): ThemeId {
  const override = getStoredOverride();
  if (override) return override;
  try {
    const platform = normalizeTheme(localStorage.getItem(PLATFORM_THEME_KEY));
    if (platform) return platform;
  } catch {
    /* localStorage puede no estar disponible */
  }
  return systemTheme();
}

export type PersistMode = 'user' | 'platform' | false;

export function applyTheme(id: ThemeId, options: { persist?: PersistMode } = {}): ThemeId {
  const theme = normalizeTheme(id) ?? 'light';
  const persist = options.persist ?? false;

  if (typeof document !== 'undefined') {
    document.documentElement.dataset.theme = theme;
    const themeColor = themeById(theme).mode === 'dark' ? '#090D16' : '#0B4F9C';
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', themeColor);
  }

  try {
    localStorage.setItem(THEME_STORAGE_KEY, theme);
    if (persist === 'user') localStorage.setItem(THEME_OVERRIDE_KEY, theme);
    if (persist === 'platform') {
      localStorage.setItem(PLATFORM_THEME_KEY, theme);
      localStorage.removeItem(THEME_OVERRIDE_KEY);
    }
  } catch {
    /* almacenamiento bloqueado: el tema se aplica igualmente en esta sesión */
  }

  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(THEME_EVENT, { detail: { theme, persist } }));
  }
  return theme;
}

/** Alterna claro/oscuro dentro de la misma familia visual. */
export function toggleMode(current: ThemeId): ThemeId {
  const definition = themeById(current);
  return definition.mode === 'dark'
    ? (definition.family === 'aurora' ? 'aurora' : 'light')
    : (definition.family === 'aurora' ? 'aurora-dark' : 'dark');
}

/** Tema global publicado por el módulo de administración. */
export async function fetchPlatformTheme(): Promise<ThemeId | null> {
  try {
    const response = await fetch('/api/v1/platform/appearance', { headers: { Accept: 'application/json' } });
    if (!response.ok) return null;
    const body = await response.json() as { theme?: string };
    return normalizeTheme(body?.theme);
  } catch {
    return null;
  }
}
