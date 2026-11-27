import { fotoDeRegion } from '../utils/regiones.js'

// Fotografía protagonista de una agencia con su jerarquía de datos (referencia visual 01):
export default function FotoAgencia({ agencia, etiqueta = '' }) {
  if (!agencia) return null
  const foto = fotoDeRegion(agencia.departamento)
  return (
    <figure className={foto ? 'foto-agencia' : 'foto-agencia sin-foto'}>
      {foto && <img src={foto.url} alt={`${foto.lugar}, ${agencia.departamento}`} loading="lazy" />}
      <span className="foto-region">{etiqueta ? `${etiqueta} · ` : ''}{agencia.departamento}</span>
      <span className="foto-provincia">{agencia.provincia}</span>
      <figcaption>
        <strong>{agencia.nombre}</strong>
        <span>
          {agencia.direccion}, {agencia.distrito}
        </span>
      </figcaption>
    </figure>
  )
}
