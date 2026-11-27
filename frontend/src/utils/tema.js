// Tema claro/oscuro. La preferencia vive en localStorage (no depende de la sesión) y se aplica como data-theme en <html>.
// El mismo criterio se aplica antes de pintar con el script de index.html, para evitar parpadeos.
export const CLAVE_TEMA = 'jatsa-tema'

export function leerTema() {
  try {
    return localStorage.getItem(CLAVE_TEMA) === 'dark' ? 'dark' : 'light'
  } catch {
    return 'light'
  }
}

export function aplicarTema(tema) {
  document.documentElement.setAttribute('data-theme', tema)
}

export function guardarTema(tema) {
  try {
    localStorage.setItem(CLAVE_TEMA, tema)
  } catch {
    // Sin almacenamiento disponible: el tema solo dura mientras la página siga abierta.
  }
}
