// Validaciones de formularios de cuenta. Replican las del backend (que vuelve a validar siempre) para
// avisar al instante. Cada función devuelve el mensaje de error o '' si el valor es válido.

// Quita espacios y guiones: «987 654 323» → «987654323».
export const sinSeparadores = (texto) => texto.replace(/[\s-]+/g, '')

export function errorDocumento(tipo, numero) {
  const limpio = sinSeparadores(numero)
  if (tipo === 'DNI') return /^\d{8}$/.test(limpio) ? '' : 'El DNI tiene 8 dígitos.'
  if (tipo === 'RUC') return /^\d{11}$/.test(limpio) ? '' : 'El RUC tiene 11 dígitos.'
  return 'Elige DNI o RUC.'
}

// Documentos que el personal puede registrar en ventanilla: DNI, RUC, CE y PAS.
export function errorDocumentoPersonal(tipo, numero) {
  const limpio = sinSeparadores(numero)
  if (tipo === 'DNI' || tipo === 'RUC') return errorDocumento(tipo, numero)
  if (tipo === 'CE' || tipo === 'PAS') return /^[A-Za-z0-9]{6,12}$/.test(limpio) ? '' : 'El documento tiene de 6 a 12 letras o dígitos.'
  return 'Elige DNI, RUC, CE o PAS.'
}

export function errorTelefono(numero) {
  return /^\d{9}$/.test(sinSeparadores(numero)) ? '' : 'El teléfono tiene 9 dígitos.'
}

export function errorTelefonoAuxiliar(auxiliar, telefono) {
  const limpio = sinSeparadores(auxiliar)
  if (limpio === '') return ''
  if (!/^\d{9}$/.test(limpio)) return 'El teléfono auxiliar tiene 9 dígitos.'
  if (limpio === sinSeparadores(telefono)) return 'El teléfono auxiliar debe ser distinto del teléfono.'
  return ''
}

export function errorCorreo(correo) {
  const limpio = correo.trim()
  if (limpio === '') return ''
  return limpio.length <= 120 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(limpio) ? '' : 'Ingresa un correo válido (hasta 120 caracteres).'
}

export function errorUsuario(usuario) {
  return /^[A-Za-z0-9._-]{4,30}$/.test(usuario.trim())
    ? ''
    : 'El usuario debe tener de 4 a 30 caracteres: letras, números, punto, guion o guion bajo.'
}

export function errorContrasena(contrasena, usuario = '') {
  if (contrasena.length < 8) return 'La contraseña debe tener al menos 8 caracteres.'
  if (new TextEncoder().encode(contrasena).length > 72) return 'La contraseña es demasiado larga (máximo 72 caracteres).'
  if (usuario !== '' && contrasena.toLowerCase() === usuario.trim().toLowerCase()) return 'La contraseña no puede ser igual al usuario.'
  return ''
}

// Quita los campos sin error de un objeto { campo: mensaje }.
export const soloConError = (errores) => Object.fromEntries(Object.entries(errores).filter(([, mensaje]) => mensaje))
