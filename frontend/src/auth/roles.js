// Roles del sistema. Los valores coinciden con USUARIO.rol (en mayúsculas);
// VISITANTE es quien no tiene sesión. La autorización real se aplica en el backend.
export const ROLES = {
  VISITANTE: 'VISITANTE',
  CLIENTE: 'CLIENTE',
  EMPRESARIO: 'EMPRESARIO',
  ADMINISTRADOR: 'ADMINISTRADOR',
  TRANSPORTISTA: 'TRANSPORTISTA',
  GERENTE: 'GERENTE',
}
