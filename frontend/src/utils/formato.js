// "054201450" → "054 201 450" (9 dígitos en grupos de 3, como en el prototipo).
export function formatoTelefono(numero) {
  const texto = String(numero)
  return /^\d{9}$/.test(texto) ? `${texto.slice(0, 3)} ${texto.slice(3, 6)} ${texto.slice(6)}` : texto
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
