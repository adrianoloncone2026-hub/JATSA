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

// GET /api/rastreo/{codigo} — estado, recorrido y llegada estimada de un envío (público; sin documentos ni teléfonos).
export async function rastrearEnvio(codigo, signal) {
  const datos = await solicitar(`/rastreo/${encodeURIComponent(codigo)}`, { signal })
  return datos.envio
}

// GET /api/salidas?origen=&destino=&tipo= — rutas con su horario habitual, tiempo y precio «desde» (público).
export async function listarSalidas({ origen = '', destino = '', tipo = '' } = {}, signal) {
  const consulta = new URLSearchParams()
  if (origen) consulta.set('origen', origen)
  if (destino) consulta.set('destino', destino)
  if (tipo) consulta.set('tipo', tipo)
  const texto = consulta.toString()
  const datos = await solicitar(`/salidas${texto ? `?${texto}` : ''}`, { signal })
  return datos.salidas
}

// GET /api/salidas/{id} — una ruta («MOQ001-AQP001») con sus precios, horarios y salidas recientes (público).
export async function obtenerRuta(id, signal) {
  const datos = await solicitar(`/salidas/${encodeURIComponent(id)}`, { signal })
  return datos.ruta
}
