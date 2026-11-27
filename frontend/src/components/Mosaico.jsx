const BASE = `${import.meta.env.BASE_URL}imagenes/azules/`

export function ImagenAzul({ id, className = '' }) {
  return <img className={className} src={`${BASE}${id}.png`} alt="" loading="lazy" />
}

// ids: números de imagen. vertical: apilado en columnas (para paneles laterales). alto: alto de las celdas.
export default function Mosaico({ ids, vertical = false, className = '' }) {
  return (
    <div className={`mosaico-azul${vertical ? ' vertical' : ''} ${className}`.trim()} aria-hidden="true">
      {ids.map((id, i) => (
        <span key={`${id}-${i}`} className={`celda-azul ${id <= 24 || (id >= 25 && id <= 49) ? 'tipo-icono' : 'tipo-escena'}`}>
          <ImagenAzul id={id} />
        </span>
      ))}
    </div>
  )
}
