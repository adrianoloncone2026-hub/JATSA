const BASE = (import.meta.env.VITE_API_URL ?? '/api').replace(/\/$/, '')

export class ErrorApi extends Error {
  constructor(estado, codigo, mensaje, campos = {}) {
    super(mensaje)
    this.name = 'ErrorApi'
    this.estado = estado
    this.codigo = codigo
    this.campos = campos
  }
}

// Respuesta correcta del backend: { data }. Error: { error: { codigo, mensaje } }.
export async function solicitar(ruta, { metodo = 'GET', cuerpo, signal } = {}) {
  let respuesta
  try {
    respuesta = await fetch(`${BASE}${ruta}`, {
      method: metodo,
      headers: {
        Accept: 'application/json',
        // El backend exige esta cabecera en toda petición que modifica datos (protección CSRF).
        'X-Requested-With': 'JATSA',
        ...(cuerpo !== undefined ? { 'Content-Type': 'application/json' } : {}),
      },
      body: cuerpo !== undefined ? JSON.stringify(cuerpo) : undefined,
      credentials: 'include',
      signal,
    })
  } catch (error) {
    if (error.name === 'AbortError') throw error
    throw new ErrorApi(0, 'sin_conexion', 'No se pudo conectar con el servidor.')
  }

  const texto = await respuesta.text()
  let contenido = null
  if (texto) {
    try {
      contenido = JSON.parse(texto)
    } catch {
      contenido = null
    }
  }

  if (!respuesta.ok) {
    if ([502, 503, 504].includes(respuesta.status) && !contenido?.error) {
      throw new ErrorApi(0, 'sin_conexion', 'No se pudo conectar con el servidor.')
    }
    throw new ErrorApi(
      respuesta.status,
      contenido?.error?.codigo ?? 'error',
      contenido?.error?.mensaje ?? 'Ocurrió un error inesperado.',
      contenido?.error?.campos ?? {},
    )
  }

  return contenido?.data ?? null
}

// Descarga de un archivo (p. ej. .xlsx): devuelve { blob, nombre }. Los errores llegan como en `solicitar`.
export async function descargar(ruta, { signal } = {}) {
  let respuesta
  try {
    respuesta = await fetch(`${BASE}${ruta}`, { headers: { 'X-Requested-With': 'JATSA' }, credentials: 'include', signal })
  } catch (error) {
    if (error.name === 'AbortError') throw error
    throw new ErrorApi(0, 'sin_conexion', 'No se pudo conectar con el servidor.')
  }

  if (!respuesta.ok) {
    let contenido = null
    try {
      contenido = await respuesta.json()
    } catch {
      contenido = null
    }
    if ([502, 503, 504].includes(respuesta.status) && !contenido?.error) {
      throw new ErrorApi(0, 'sin_conexion', 'No se pudo conectar con el servidor.')
    }
    throw new ErrorApi(respuesta.status, contenido?.error?.codigo ?? 'error', contenido?.error?.mensaje ?? 'No se pudo generar el archivo.', contenido?.error?.campos ?? {})
  }

  const nombre = /filename="([^"]+)"/.exec(respuesta.headers.get('Content-Disposition') ?? '')?.[1] ?? 'JATSA.xlsx'
  return { blob: await respuesta.blob(), nombre }
}
