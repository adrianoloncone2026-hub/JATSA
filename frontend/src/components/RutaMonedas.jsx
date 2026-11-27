import Moneda from './Moneda.jsx'

// Origen → destino. Cada moneda lleva la foto de su región y, debajo, el nombre de la sucursal cuando se conoce:
export default function RutaMonedas({ origen = '', destino = '', nombreOrigen = '', nombreDestino = '', tamano = 56 }) {
  return (
    <span className="ruta-monedas">
      <span className="ruta-moneda" aria-hidden="true">
        <Moneda departamento={origen} tamano={tamano} />
        {nombreOrigen && <small>{nombreOrigen}</small>}
      </span>
      <span aria-hidden="true">→</span>
      <span className="ruta-moneda" aria-hidden="true">
        <Moneda departamento={destino} tamano={tamano} />
        {nombreDestino && <small>{nombreDestino}</small>}
      </span>
    </span>
  )
}
