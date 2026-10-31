import { solicitar } from './cliente.js'

// GET /api/reservas?estado=PENDIENTE — reservas del cliente de la sesión (más recientes primero).
export async function listarReservas(estado = '', signal) {
  const consulta = estado ? `?${new URLSearchParams({ estado })}` : ''
  const datos = await solicitar(`/reservas${consulta}`, { signal })
  return datos.reservas
}

// POST /api/reservas — crea una reserva PENDIENTE por el canal WEB. Devuelve la reserva.
export async function crearReserva(datos) {
  const respuesta = await solicitar('/reservas', { metodo: 'POST', cuerpo: datos })
  return respuesta.reserva
}

// GET /api/reservas/{codigo}
export async function obtenerReserva(codigo, signal) {
  const datos = await solicitar(`/reservas/${encodeURIComponent(codigo)}`, { signal })
  return datos.reserva
}

// POST /api/reservas/{codigo}/cancelar — solo reservas PENDIENTES. Devuelve la reserva cancelada.
export async function cancelarReserva(codigo) {
  const datos = await solicitar(`/reservas/${encodeURIComponent(codigo)}/cancelar`, { metodo: 'POST' })
  return datos.reserva
}

// GET /api/reservas/destinatario — { existe, nombreCompleto?, esUsted? }. Autocompleta el nombre por documento.
export function consultarDestinatario({ tipoDocumento, numeroDocumento }, signal) {
  const consulta = new URLSearchParams({ tipoDocumento, numeroDocumento })
  return solicitar(`/reservas/destinatario?${consulta}`, { signal })
}
