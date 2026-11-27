import { descargar, solicitar } from './cliente.js'


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

// GET /api/reportes/exportar/{gerencia|logistica}?sucursal= — descarga el .xlsx (módulo 12). El ADMINISTRADOR solo obtiene su sucursal.
export function descargarPowerBi(tipo, filtros = {}, signal) {
  return descargar(`/reportes/exportar/${tipo}-powerbi${consulta(filtros)}`, { signal })
}

export function descargarExcel(tipo, filtros = {}, signal) {
  return descargar(`/reportes/exportar/${tipo}${consulta(filtros)}`, { signal })
}
