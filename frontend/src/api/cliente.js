// Cliente HTTP único. Toda comunicación con el backend pasa por aquí.
// La URL base sale de VITE_API_URL (por defecto "/api", reenviada al backend por Vite en desarrollo).
const BASE = (import.meta.env.VITE_API_URL ?? '/api').replace(/\/$/, '')

export class ErrorApi extends Error {
  constructor(estado, codigo, mensaje) {
    super(mensaje)
    this.name = 'ErrorApi'
    this.estado = estado // 0 = sin conexión con el servidor
    this.codigo = codigo
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
    // 502/503/504 sin error JSON de la API: lo emite el proxy (o un servidor intermedio)
    // porque el backend no responde. Un 503 propio de la API sí trae { error } y se respeta.
    if ([502, 503, 504].includes(respuesta.status) && !contenido?.error) {
      throw new ErrorApi(0, 'sin_conexion', 'No se pudo conectar con el servidor.')
    }
    throw new ErrorApi(
      respuesta.status,
      contenido?.error?.codigo ?? 'error',
      contenido?.error?.mensaje ?? 'Ocurrió un error inesperado.',
    )
  }

  return contenido?.data ?? null
}
