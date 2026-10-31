import { solicitar } from './cliente.js'

// Ventanilla y envíos del ADMINISTRADOR (módulo 5). Todo se limita a la agencia de la sesión: el servidor la toma
// de la sesión, nunca de lo que se envíe aquí.

// GET /api/ventanilla/reservas — reservas pendientes de la agencia que aún están dentro de las 72 h.
export async function listarReservasPendientes(signal) {
  const datos = await solicitar('/ventanilla/reservas', { signal })
  return datos.reservas
}

// GET /api/ventanilla/reservas/{codigo} — una reserva de la agencia (en cualquier estado); 404 si no es de esta agencia.
export async function buscarReservaVentanilla(codigo, signal) {
  const datos = await solicitar(`/ventanilla/reservas/${encodeURIComponent(codigo)}`, { signal })
  return datos.reserva
}

// GET /api/ventanilla/personas — { existe, nombreCompleto? }. Autocompleta el nombre por documento.
export function consultarPersona({ tipoDocumento, numeroDocumento }, signal) {
  const consulta = new URLSearchParams({ tipoDocumento, numeroDocumento })
  return solicitar(`/ventanilla/personas?${consulta}`, { signal })
}

// POST /api/ventanilla/reservas — «Cliente sin reserva»: crea una reserva con canal VENTANILLA. Devuelve la reserva.
export async function crearReservaVentanilla(datos) {
  const respuesta = await solicitar('/ventanilla/reservas', { metodo: 'POST', cuerpo: datos })
  return respuesta.reserva
}

// POST /api/envios/cotizacion — tipo, costo y error de cada bulto, e importe. No escribe nada.
export function cotizarBultos({ codigoReserva, bultos }, signal) {
  return solicitar('/envios/cotizacion', { metodo: 'POST', cuerpo: { codigoReserva, bultos }, signal })
}

// POST /api/envios — formaliza la reserva: crea el envío con sus bultos. Devuelve el envío.
export async function formalizarEnvio(datos) {
  const respuesta = await solicitar('/envios', { metodo: 'POST', cuerpo: datos })
  return respuesta.envio
}

// GET /api/envios/{codigo} — un envío (para la etiqueta).
export async function obtenerEnvio(codigo, signal) {
  const datos = await solicitar(`/envios/${encodeURIComponent(codigo)}`, { signal })
  return datos.envio
}
