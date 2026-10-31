// Roles del sistema. Los valores coinciden con Usuario.rol (en mayúsculas);
// VISITANTE es quien no tiene sesión. La autorización real se aplica en el backend.
export const ROLES = {
  VISITANTE: 'VISITANTE',
  CLIENTE: 'CLIENTE',
  EMPRESARIO: 'EMPRESARIO',
  ADMINISTRADOR: 'ADMINISTRADOR',
  TRANSPORTISTA: 'TRANSPORTISTA',
  GERENTE: 'GERENTE',
}

// Pantalla inicial de cada rol tras ingresar (CLIENTE y EMPRESARIO comparten área).
const INICIO_POR_ROL = {
  [ROLES.CLIENTE]: '/cliente',
  [ROLES.EMPRESARIO]: '/cliente',
  [ROLES.ADMINISTRADOR]: '/administrador',
  [ROLES.TRANSPORTISTA]: '/transportista',
  [ROLES.GERENTE]: '/gerente',
}

export function rutaInicioDeRol(rol) {
  return INICIO_POR_ROL[rol] ?? '/'
}
