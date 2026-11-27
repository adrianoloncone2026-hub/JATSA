import { useEffect, useMemo, useState } from 'react'
import { obtenerAgencias } from '../api/publico.js'

// Agencias activas por código (para mostrar región, provincia y dirección junto a una ruta).
export function useAgencias() {
  const [lista, setLista] = useState([])
  useEffect(() => {
    const control = new AbortController()
    obtenerAgencias(control.signal)
      .then(setLista)
      .catch(() => {})
    return () => control.abort()
  }, [])
  return useMemo(() => ({ lista, porCodigo: new Map(lista.map((a) => [a.codigo, a])) }), [lista])
}
