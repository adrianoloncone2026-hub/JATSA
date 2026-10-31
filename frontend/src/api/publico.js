import { solicitar } from './cliente.js'

// GET /api/agencias — sucursales activas con teléfonos (público).
export async function obtenerAgencias(signal) {
  const datos = await solicitar('/agencias', { signal })
  return datos.agencias
}

// GET /api/cotizacion?origen=MOQ001&destino=AQP001&peso=8.80 (público).
export function cotizar({ origen, destino, peso }, signal) {
  const consulta = new URLSearchParams({ origen, destino, peso })
  return solicitar(`/cotizacion?${consulta}`, { signal })
}
