import { solicitar } from './cliente.js'

// Pagos y Caja (módulo 6). Caja se limita a la agencia de la sesión: el servidor la toma de la sesión, nunca de lo que
// se envíe aquí. Un pago no se borra: se anula.

// GET /api/caja/envios/{codigo} — el envío a cobrar: importe, pagado, saldo y sus pagos. 404 si no es de esta agencia.
export async function obtenerEnvioParaCobro(codigo, signal) {
  const datos = await solicitar(`/caja/envios/${encodeURIComponent(codigo)}`, { signal })
  return datos.envio
}

// POST /api/caja/pagos — registra un pago. Devuelve { pago, envio } (el envío ya con el saldo actualizado).
export function registrarPago({ codigoSeguimiento, monto, pagadoPor, metodoPago }) {
  return solicitar('/caja/pagos', { metodo: 'POST', cuerpo: { codigoSeguimiento, monto, pagadoPor, metodoPago } })
}

// POST /api/caja/pagos/{id}/anular — anula un pago cobrado en esta caja. Devuelve el pago ya ANULADO.
export async function anularPago(id) {
  const datos = await solicitar(`/caja/pagos/${encodeURIComponent(id)}/anular`, { metodo: 'POST' })
  return datos.pago
}

// GET /api/caja/pagos — { sucursal, pagos, hayMas, totales } de lo cobrado en esta caja.
export function listarPagosCobrados(signal) {
  return solicitar('/caja/pagos', { signal })
}

// GET /api/pagos — { pagos, hayMas, totalPagado } de los pagos que hizo el cliente o empresario de la sesión.
export function listarMisPagos(signal) {
  return solicitar('/pagos', { signal })
}
