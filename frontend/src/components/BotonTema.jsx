import { useEffect, useState } from 'react'
import { aplicarTema, guardarTema, leerTema } from '../utils/tema.js'

// Alterna modo claro/oscuro. Muestra el icono del modo al que se cambiaría (sol en oscuro, luna en claro).
export default function BotonTema() {
  const [tema, setTema] = useState(leerTema)
  const oscuro = tema === 'dark'

  useEffect(() => {
    aplicarTema(tema)
  }, [tema])

  function alternar() {
    const siguiente = oscuro ? 'light' : 'dark'
    guardarTema(siguiente)
    setTema(siguiente)
  }

  return (
    <button type="button" className="boton-tema" onClick={alternar} aria-pressed={oscuro} aria-label={oscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'} title={oscuro ? 'Modo claro' : 'Modo oscuro'}>
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        {oscuro ? (
          <>
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
          </>
        ) : (
          <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
        )}
      </svg>
    </button>
  )
}
