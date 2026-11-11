// Iconos de la interfaz. Se cargan desde el sprite SVG de marca.
export function Icon({ name, className = '' }: { name: string; className?: string }) {
  return <svg className={'ui-icon ' + className} aria-hidden="true" focusable="false"><use href={import.meta.env.BASE_URL + 'assets/sederg-pay/icons/sprite.svg#' + name} /></svg>;
}
