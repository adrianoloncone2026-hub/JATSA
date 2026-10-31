import casa from '../assets/moneda-casa.png'
import edificio from '../assets/moneda-edificio.png'
import iglesia from '../assets/moneda-iglesia.png'

const ICONOS = { casa, edificio, iglesia }

// Ícono de cada departamento (decorativo, como las «monedas» del prototipo). Los demás usan la casa.
const ICONO_POR_DEPARTAMENTO = { lima: 'iglesia', arequipa: 'edificio', moquegua: 'casa' }

export function iconoDeDepartamento(departamento) {
  return ICONO_POR_DEPARTAMENTO[String(departamento).trim().toLowerCase()] ?? 'casa'
}

// Moneda circular con el ícono. Es decorativa: el texto que la acompaña dice lo mismo.
export default function Moneda({ icono = 'casa', tamano = 64 }) {
  return <img className="moneda" src={ICONOS[icono] ?? casa} alt="" width={tamano} height={tamano} />
}
