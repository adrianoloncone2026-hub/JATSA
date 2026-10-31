import { ErrorApi, solicitar } from './cliente.js'

// POST /api/auth/ingresar — devuelve el usuario autenticado.
export async function ingresar({ usuario, contrasena, recordarme }) {
  const datos = await solicitar('/auth/ingresar', {
    metodo: 'POST',
    cuerpo: { usuario, contrasena, recordarme },
  })
  return datos.usuario
}

// POST /api/auth/salir
export function salir() {
  return solicitar('/auth/salir', { metodo: 'POST' })
}

// GET /api/auth/yo — usuario de la sesión actual, o null si no hay sesión.
export async function obtenerSesion(signal) {
  try {
    const datos = await solicitar('/auth/yo', { signal })
    return datos.usuario
  } catch (error) {
    if (error instanceof ErrorApi && error.estado === 401) return null
    throw error
  }
}
