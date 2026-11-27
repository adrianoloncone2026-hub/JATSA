import { solicitar } from './cliente.js'

// Llegadas y entregas del ADMINISTRADOR (módulo 8). Todo se limita a la agencia de destino de la sesión: el servidor la toma

// GET /api/entregas/pendientes — { sucursal, enCamino, listos, hayMas }.
export function listarPendientes(signal) {
  return solicitar('/entregas/pendientes', { signal })
}

// GET /api/entregas/envios/{codigo} — un envío de la agencia de destino (con el documento del destinatario, solo para el personal).
export async function obtenerEnvioParaEntrega(codigo, signal) {
  const datos = await solicitar(`/entregas/envios/${encodeURIComponent(codigo)}`, { signal })
  return datos.envio
}

// POST /api/entregas/envios/{codigo}/entrega — confirma la entrega con el documento presentado. Devuelve el envío ENTREGADO.
export async function confirmarEntrega(codigo, { tipoDocumento, numeroDocumento }) {
  const datos = await solicitar(`/entregas/envios/${encodeURIComponent(codigo)}/entrega`, { metodo: 'POST', cuerpo: { tipoDocumento, numeroDocumento } })
  return datos.envio
}

// POST /api/entregas/envios/{codigo}/devolucion — marca el envío como devuelto. Devuelve el envío DEVUELTO.
export async function marcarDevuelto(codigo) {
  const datos = await solicitar(`/entregas/envios/${encodeURIComponent(codigo)}/devolucion`, { metodo: 'POST' })
  return datos.envio
}
