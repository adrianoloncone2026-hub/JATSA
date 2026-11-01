import { solicitar } from './cliente.js'

// Despacho del ADMINISTRADOR (módulo 7). Todo se limita a la agencia de la sesión: el servidor la toma de la sesión,
// nunca de lo que se envíe aquí.

// GET /api/despacho/viajes — { sucursal, viajes, hayMasTerminados }.
export function listarSalidas(signal) {
  return solicitar('/despacho/viajes', { signal })
}

// GET /api/despacho/envios-en-espera — { envios, hayMas } con el viaje sugerido de cada uno.
export function listarEnviosEnEspera(signal) {
  return solicitar('/despacho/envios-en-espera', { signal })
}

// GET /api/despacho/opciones — { vehiculos, transportistas, destinos } para «Programar viaje».
export function obtenerOpciones(signal) {
  return solicitar('/despacho/opciones', { signal })
}

// POST /api/despacho/viajes — programa un viaje. Devuelve el viaje.
export async function programarViaje({ placa, transportista, destino }) {
  const datos = await solicitar('/despacho/viajes', { metodo: 'POST', cuerpo: { placa, transportista, destino } })
  return datos.viaje
}

// POST /api/despacho/viajes/{codigo}/carga — carga un envío en un viaje programado. Devuelve el viaje con su carga.
export async function cargarEnvio(codigoViaje, codigoSeguimiento) {
  const datos = await solicitar(`/despacho/viajes/${encodeURIComponent(codigoViaje)}/carga`, { metodo: 'POST', cuerpo: { codigoSeguimiento } })
  return datos.viaje
}

// DELETE /api/despacho/viajes/{codigo}/carga/{seguimiento} — quita un envío de un viaje que no salió.
export async function quitarEnvio(codigoViaje, codigoSeguimiento) {
  const datos = await solicitar(`/despacho/viajes/${encodeURIComponent(codigoViaje)}/carga/${encodeURIComponent(codigoSeguimiento)}`, { metodo: 'DELETE' })
  return datos.viaje
}
