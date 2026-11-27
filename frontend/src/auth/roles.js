// Roles del sistema. Los valores coinciden con Usuario.rol (en mayúsculas);
export const ROLES = {
  VISITANTE: 'VISITANTE',
  CLIENTE: 'CLIENTE',
  EMPRESARIO: 'EMPRESARIO',
  ADMINISTRADOR: 'ADMINISTRADOR',
  TRANSPORTISTA: 'TRANSPORTISTA',
  GERENTE: 'GERENTE',
}

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
