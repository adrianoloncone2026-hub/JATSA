import Moneda, { iconoDeDepartamento } from './Moneda.jsx'

// Origen → destino con sus «monedas» (decorativas). Recibe el departamento de cada sucursal.
export default function RutaMonedas({ origen = '', destino = '', tamano = 56 }) {
  return (
    <span className="ruta-monedas" aria-hidden="true">
      <Moneda icono={iconoDeDepartamento(origen)} tamano={tamano} />
      <span>→</span>
      <Moneda icono={iconoDeDepartamento(destino)} tamano={tamano} />
    </span>
  )
}
