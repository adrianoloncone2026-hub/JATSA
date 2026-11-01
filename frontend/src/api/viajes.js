import { solicitar } from './cliente.js'

// Viajes (módulo 7). «Mis viajes», salida y llegada son del TRANSPORTISTA; el detalle también lo ve el administrador de la agencia.

// GET /api/viajes?estado= — { viajes, hayMas } del transportista de la sesión. estado: '' | PROGRAMADO | EN_RUTA | TERMINADO.
export function listarMisViajes(estado, signal) {
  const consulta = estado ? `?estado=${encodeURIComponent(estado)}` : ''
  return solicitar(`/viajes${consulta}`, { signal })
}

// GET /api/viajes/{codigo} — un viaje con su carga. 404 si no es del usuario.
export async function obtenerViaje(codigo, signal) {
  const datos = await solicitar(`/viajes/${encodeURIComponent(codigo)}`, { signal })
  return datos.viaje
}

// POST /api/viajes/{codigo}/salida — marca la salida con la hora del servidor. Devuelve el viaje.
export async function marcarSalida(codigo) {
  const datos = await solicitar(`/viajes/${encodeURIComponent(codigo)}/salida`, { metodo: 'POST' })
  return datos.viaje
}

// POST /api/viajes/{codigo}/llegada — marca la llegada con la hora del servidor. Devuelve el viaje.
export async function marcarLlegada(codigo) {
  const datos = await solicitar(`/viajes/${encodeURIComponent(codigo)}/llegada`, { metodo: 'POST' })
  return datos.viaje
}
