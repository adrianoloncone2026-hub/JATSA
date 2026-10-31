import { solicitar } from './cliente.js'

// GET /api/salud — comprobación de que la API responde (no usa la base de datos).
export function obtenerSalud(signal) {
  return solicitar('/salud', { signal })
}
