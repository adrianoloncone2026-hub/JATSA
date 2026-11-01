import { solicitar } from './cliente.js'

// Cuentas de usuario del GERENTE (módulo 10). Nunca devuelven contraseñas, hashes, documentos ni teléfonos.

// GET /api/usuarios?q=&rol=&estado= — { cuentas, hayMas }.
export function listarCuentas(filtros = {}, signal) {
  const consulta = new URLSearchParams()
  for (const [clave, valor] of Object.entries(filtros)) if (valor) consulta.set(clave, valor)
  const texto = consulta.toString()
  return solicitar(`/usuarios${texto ? `?${texto}` : ''}`, { signal })
}

// GET /api/usuarios/persona?tipoDocumento=&numeroDocumento= — { existe, nombreCompleto?, tieneCuenta? }.
export async function consultarPersona(tipoDocumento, numeroDocumento, signal) {
  const consulta = new URLSearchParams({ tipoDocumento, numeroDocumento })
  return (await solicitar(`/usuarios/persona?${consulta}`, { signal })).persona
}

// POST /api/usuarios — «Crear cuenta» de personal para una persona que ya existe. Devuelve la cuenta.
export async function crearCuenta(datos) {
  return (await solicitar('/usuarios', { metodo: 'POST', cuerpo: datos })).cuenta
}

// PUT /api/usuarios/{usuario} — «Editar»: rol y sucursal del personal.
export async function editarCuenta(usuario, datos) {
  return (await solicitar(`/usuarios/${encodeURIComponent(usuario)}`, { metodo: 'PUT', cuerpo: datos })).cuenta
}

// POST /api/usuarios/{usuario}/estado — { activo: boolean }: «Desactivar seleccionada» y volver a activar.
export async function cambiarEstadoCuenta(usuario, activo) {
  return (await solicitar(`/usuarios/${encodeURIComponent(usuario)}/estado`, { metodo: 'POST', cuerpo: { activo } })).cuenta
}