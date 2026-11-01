// "054201450" → "054 201 450" (9 dígitos en grupos de 3, como en el prototipo).
export function formatoTelefono(numero) {
  const texto = String(numero)
  return /^\d{9}$/.test(texto) ? `${texto.slice(0, 3)} ${texto.slice(3, 6)} ${texto.slice(6)}` : texto
}

// "María Elena Rojas Ccori" → "MR" (nombre y primer apellido). Con un nombre de dos palabras usa ambas.
export function iniciales(nombreCompleto) {
  const palabras = nombreCompleto.trim().split(/\s+/).filter(Boolean)
  if (palabras.length === 0) return '?'
  const segunda = palabras.length >= 3 ? palabras[palabras.length - 2] : palabras[1]
  return (palabras[0][0] + (segunda?.[0] ?? '')).toUpperCase()
}

// "María Elena Rojas Ccori" → "María Elena" (saludo de «Mi panel»). Una empresa conserva su razón social.
export function saludoDe(nombreCompleto, esEmpresa = false) {
  const palabras = nombreCompleto.trim().split(/\s+/).filter(Boolean)
  if (esEmpresa || palabras.length <= 1) return nombreCompleto.trim()
  return palabras.slice(0, palabras.length >= 4 ? 2 : 1).join(' ')
}

// "2026-03-01" → "01/03/2026"
export function fechaCorta(fechaIso) {
  const [anio, mes, dia] = String(fechaIso).slice(0, 10).split('-')
  return anio && mes && dia ? `${dia}/${mes}/${anio}` : String(fechaIso)
}

// "2026-11-02 10:00:00" → "02/11/2026 10:00"
export function fechaHoraCorta(fechaHoraIso) {
  const texto = String(fechaHoraIso)
  const hora = texto.slice(11, 16)
  return hora ? `${fechaCorta(texto)} ${hora}` : fechaCorta(texto)
}

// "2026-10-14 14:05:00" → "14/10 14:05" (fecha y hora sin el año, como en las tablas de Caja del prototipo)
export function fechaHoraBreve(fechaHoraIso) {
  const texto = String(fechaHoraIso)
  const [anio, mes, dia] = texto.slice(0, 10).split('-')
  const hora = texto.slice(11, 16)
  return anio && mes && dia ? `${dia}/${mes}${hora ? ` ${hora}` : ''}` : texto
}

// "2026-10-14 19:00:00" → "19:00"
export function horaCorta(fechaHoraIso) {
  return String(fechaHoraIso).slice(11, 16)
}

// "1500.00" → "1500"; "18.50" → "18.50" (la capacidad entera sin decimales, el peso con ellos)
export function kilosSinCeros(kg) {
  return String(kg).replace(/\.00$/, '')
}

// "Av. Ejército 512, Cercado, Arequipa": dirección, distrito, provincia y departamento sin repetir nombres.
export function direccionCompleta(agencia) {
  const vistos = new Set()
  return [agencia.direccion, agencia.distrito, agencia.provincia, agencia.departamento]
    .filter((parte) => {
      const clave = parte.trim().toLowerCase()
      if (vistos.has(clave)) return false
      vistos.add(clave)
      return true
    })
    .join(', ')
}
