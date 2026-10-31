import { solicitar } from './cliente.js'

// GET /api/agencias — sucursales activas con teléfonos (público).
export async function obtenerAgencias(signal) {
  const datos = await solicitar('/agencias', { signal })
  return datos.agencias
}

// GET /api/tarifas?origen=MOQ001&destino=AQP001 — tarifas vigentes de la ruta, sin peso (público).
export function obtenerTarifas({ origen, destino }, signal) {
  const consulta = new URLSearchParams({ origen, destino })
  return solicitar(`/tarifas?${consulta}`, { signal })
}

// GET /api/cotizacion?origen=MOQ001&destino=AQP001&peso=8.80 (público).
export function cotizar({ origen, destino, peso }, signal) {
  const consulta = new URLSearchParams({ origen, destino, peso })
  return solicitar(`/cotizacion?${consulta}`, { signal })
}
