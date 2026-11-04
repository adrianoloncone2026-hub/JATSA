import { solicitar } from './cliente.js'

// Resumen y Viajes y flota del GERENTE (módulo 11). Solo lectura; el backend exige el rol GERENTE.

function consulta(filtros) {
  const parametros = new URLSearchParams()
  for (const [clave, valor] of Object.entries(filtros)) if (valor) parametros.set(clave, valor)
  const texto = parametros.toString()
  return texto ? `?${texto}` : ''
}

// GET /api/reportes/resumen?mes=AAAA-MM&sucursal=CÓDIGO — indicadores del mes (diapositiva 26).
export function obtenerResumen(filtros = {}, signal) {
  return solicitar(`/reportes/resumen${consulta(filtros)}`, { signal })
}

// GET /api/reportes/viajes-flota?anio=&mes=&sucursal=&vehiculo=&transportista=&tipoEnvio= — diapositiva 28.
export function obtenerViajesFlota(filtros = {}, signal) {
  return solicitar(`/reportes/viajes-flota${consulta(filtros)}`, { signal })
}
