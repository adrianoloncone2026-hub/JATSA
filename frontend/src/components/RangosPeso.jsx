import { kilosSinCeros } from '../utils/formato.js'

// Rangos de peso que clasifican un bulto en SOBRE, PAQUETE o CARGA. Los límites vienen de la API
export default function RangosPeso({ tarifas, resaltado = '', conPrecio = false }) {
  const ordenadas = [...tarifas].sort((a, b) => a.pesoMaximoKg - b.pesoMaximoKg)
  return (
    <ol className="rangos-peso" aria-label="Rangos de peso por tipo de envío">
      {ordenadas.map((tarifa, i) => {
        const desde = i === 0 ? null : kilosSinCeros(ordenadas[i - 1].pesoMaximoKg)
        const clases = ['rango', tarifa.tipo === resaltado ? 'resaltado' : '', tarifa.disponible === false ? 'sin-ruta' : ''].filter(Boolean).join(' ')
        return (
          <li key={tarifa.tipo} className={clases}>
            <span className="rango-tipo">{tarifa.tipo}</span>
            <span className="rango-peso">{desde === null ? `hasta ${kilosSinCeros(tarifa.pesoMaximoKg)} kg` : `más de ${desde} hasta ${kilosSinCeros(tarifa.pesoMaximoKg)} kg`}</span>
            {conPrecio && (
              <span className="rango-precio">{tarifa.disponible === false ? 'sin ruta vigente' : `S/ ${tarifa.precioPorBulto} por bulto`}</span>
            )}
          </li>
        )
      })}
    </ol>
  )
}
