import { useEffect, useState } from 'react';
import { Icon } from './icons';
import { onToast, ToastItem } from './toast';

// =============================================================================
// ToastHost — pila de avisos emergentes.
//
// Se monta una sola vez (en la raiz de la aplicacion) y escucha el bus de
// avisos. Cada aviso se cierra solo pasado su duracion o al pulsar la X.
// =============================================================================

export function ToastHost() {
  const [items, setItems] = useState<ToastItem[]>([]);

  useEffect(() => onToast((toast) => {
    setItems((current) => [...current, toast]);
    if (toast.duration > 0) {
      window.setTimeout(() => {
        setItems((current) => current.filter((item) => item.id !== toast.id));
      }, toast.duration);
    }
  }), []);

  if (items.length === 0) return null;

  return <div className="toast-stack" role="status" aria-live="polite">
    {items.map((item) => <div key={item.id} className={`toast toast-${item.type}`}>
      <span className="toast-icon"><Icon name={item.type === 'success' ? 'check' : item.type === 'error' ? 'alert-circle' : item.type === 'warning' ? 'alert-circle' : 'info'} /></span>
      <span className="toast-message">{item.message}</span>
      <button type="button" className="toast-close" aria-label="Cerrar aviso" onClick={() => setItems((current) => current.filter((entry) => entry.id !== item.id))}>×</button>
    </div>)}
  </div>;
}
