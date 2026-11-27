import casa from '../assets/moneda-casa.png'
import edificio from '../assets/moneda-edificio.png'
import iglesia from '../assets/moneda-iglesia.png'
import { fotoDeRegion } from '../utils/regiones.js'

const ICONOS = { casa, edificio, iglesia }

const ICONO_POR_DEPARTAMENTO = { lima: 'iglesia', arequipa: 'edificio', moquegua: 'casa' }

export function iconoDeDepartamento(departamento) {
  return ICONO_POR_DEPARTAMENTO[String(departamento).trim().toLowerCase()] ?? 'casa'
}

// Moneda circular. Con `departamento` muestra la fotografía de su región; si no hay foto, usa el ícono.
export default function Moneda({ icono, departamento, tamano = 64 }) {
  const foto = departamento ? fotoDeRegion(departamento) : null
  const origen = foto ? foto.miniatura : (ICONOS[icono ?? iconoDeDepartamento(departamento ?? '')] ?? casa)
  return <img className={foto ? 'moneda moneda-foto' : 'moneda'} src={origen} alt="" width={tamano} height={tamano} loading="lazy" />
}
