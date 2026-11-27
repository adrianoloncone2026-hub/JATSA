import { useState } from 'react'

// Carrusel simple de fotografías con puntos de navegación (referencia visual 03). Con una sola foto no muestra controles.
export default function Carrusel({ fotos, etiqueta = 'Fotografías' }) {
  const [indice, setIndice] = useState(0)
  if (fotos.length === 0) return null
  const actual = fotos[Math.min(indice, fotos.length - 1)]
  const ir = (delta) => setIndice((i) => (i + delta + fotos.length) % fotos.length)

  return (
    <figure className="carrusel" aria-roledescription="carrusel" aria-label={etiqueta}>
      <img src={actual.url} alt={actual.lugar} loading="lazy" />
      <figcaption>{actual.lugar}</figcaption>
      {fotos.length > 1 && (
        <>
          <button type="button" className="carrusel-flecha carrusel-anterior" onClick={() => ir(-1)} aria-label="Foto anterior">
            ‹
          </button>
          <button type="button" className="carrusel-flecha carrusel-siguiente" onClick={() => ir(1)} aria-label="Foto siguiente">
            ›
          </button>
          <div className="carrusel-puntos">
            {fotos.map((foto, i) => (
              <button
                key={foto.url}
                type="button"
                className={i === indice ? 'punto activo' : 'punto'}
                aria-label={`Ver ${foto.lugar}`}
                aria-current={i === indice ? 'true' : undefined}
                onClick={() => setIndice(i)}
              />
            ))}
          </div>
        </>
      )}
    </figure>
  )
}
