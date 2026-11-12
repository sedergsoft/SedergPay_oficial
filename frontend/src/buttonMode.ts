// =============================================================================
// SEDERG_PAY — modo de presentación de los botones de acción
//
// Permite alternar entre botones con texto y botones de solo icono (con
// title/aria-label para accesibilidad). La preferencia se guarda en
// localStorage y notifica a los componentes suscritos.
// =============================================================================

export type ButtonMode = 'text' | 'icons';

const STORAGE_KEY = 'sederg_button_mode';
const listeners = new Set<() => void>();

let current: ButtonMode = readInitial();

function readInitial(): ButtonMode {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    return stored === 'icons' ? 'icons' : 'text';
  } catch {
    return 'text';
  }
}

export const getButtonMode = (): ButtonMode => current;

export const setButtonMode = (mode: ButtonMode): void => {
  if (mode !== 'icons' && mode !== 'text') return;
  current = mode;
  try { localStorage.setItem(STORAGE_KEY, mode); } catch { /* almacenamiento no disponible */ }
  listeners.forEach((listener) => listener());
};

export const onButtonModeChange = (listener: () => void): (() => void) => {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
};
