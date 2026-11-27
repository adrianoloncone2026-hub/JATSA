import { solicitar } from './cliente.js'

// Historial de envíos del CLIENTE y del EMPRESARIO (módulo 9): lo que la persona de la sesión envió o recibió.

// GET /api/mis-envios?q=&rol=&estado=&desde=&hasta= — { envios, hayMas }.
export function listarMisEnvios(filtros = {}, signal) {
  const consulta = new URLSearchParams()
  for (const [clave, valor] of Object.entries(filtros)) if (valor) consulta.set(clave, valor)
  const texto = consulta.toString()
  return solicitar(`/mis-envios${texto ? `?${texto}` : ''}`, { signal })
}

// GET /api/mis-envios/{codigo} — un envío propio con su detalle. 404 si no es suyo.
export async function obtenerMiEnvio(codigo, signal) {
  const datos = await solicitar(`/mis-envios/${encodeURIComponent(codigo)}`, { signal })
  return datos.envio
}

// GET /api/mis-envios/resumen — { enCurso, saldoPendiente, porRecoger, hayMasPorRecoger } para «Mi panel».
export function obtenerResumenDeEnvios(signal) {
  return solicitar('/mis-envios/resumen', { signal })
}