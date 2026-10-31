import { solicitar } from './cliente.js'

// POST /api/cuenta/registro — crea una cuenta CLIENTE (DNI) o EMPRESARIO (RUC). No abre sesión.
export function registrarCuenta(datos) {
  return solicitar('/cuenta/registro', { metodo: 'POST', cuerpo: datos })
}

// GET /api/cuenta — { persona, usuario } de la sesión actual.
export function obtenerCuenta(signal) {
  return solicitar('/cuenta', { signal })
}

// PATCH /api/cuenta — cambia teléfono, teléfono auxiliar y correo. Devuelve la cuenta actualizada.
export function actualizarContacto(datos) {
  return solicitar('/cuenta', { metodo: 'PATCH', cuerpo: datos })
}

// POST /api/cuenta/contrasena — cambia la contraseña; las demás sesiones de la cuenta se cierran.
export function cambiarContrasena({ contrasenaActual, contrasenaNueva }) {
  return solicitar('/cuenta/contrasena', { metodo: 'POST', cuerpo: { contrasenaActual, contrasenaNueva } })
}
