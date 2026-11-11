// =============================================================================
// SEDERG_PAY — avisos emergentes (toasts)
//
// Bus de eventos muy simple: cualquier parte de la aplicacion puede lanzar un
// aviso con notify(mensaje, tipo) y el ToastHost lo muestra en pantalla.
// =============================================================================

export type ToastType = 'success' | 'error' | 'info' | 'warning';

export type ToastItem = {
  id: number;
  type: ToastType;
  message: string;
  duration: number;
};

type Listener = (toast: ToastItem) => void;

const listeners = new Set<Listener>();
let counter = 0;

/** Lanza un aviso emergente. */
export const notify = (message: string, type: ToastType = 'info', duration = 4500): void => {
  const text = (message ?? '').trim();
  if (!text) return;
  const toast: ToastItem = { id: ++counter, type, message: text, duration };
  listeners.forEach((listener) => listener(toast));
};

export const notifySuccess = (message: string): void => notify(message, 'success');
export const notifyError = (message: string): void => notify(message, 'error', 6500);
export const notifyInfo = (message: string): void => notify(message, 'info');

/** Suscribe un componente a los avisos. Devuelve la funcion para desuscribir. */
export const onToast = (listener: Listener): (() => void) => {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
};
