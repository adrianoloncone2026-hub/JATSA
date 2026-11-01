import { useSearchParams } from 'react-router-dom'
import CatalogoRutas from './CatalogoRutas.jsx'
import CatalogoSucursales from './CatalogoSucursales.jsx'
import CatalogoVehiculos from './CatalogoVehiculos.jsx'

const PESTANAS = [
  { id: 'sucursales', titulo: 'Sucursales', Contenido: CatalogoSucursales },
  { id: 'rutas', titulo: 'Rutas y precios', Contenido: CatalogoRutas },
  { id: 'vehiculos', titulo: 'Vehículos', Contenido: CatalogoVehiculos },
]

// Diapositiva 29 del prototipo: Catálogos del gerente. La pestaña activa va en la dirección (?pestana=) para poder enlazarla.
export default function Catalogos() {
  const [parametros, setParametros] = useSearchParams()
  const activa = PESTANAS.find((p) => p.id === parametros.get('pestana')) ?? PESTANAS[1]
  const { Contenido } = activa

  return (
    <section className="catalogos">
      <h1>Catálogos</h1>
      <div className="pestanas" role="tablist" aria-label="Catálogos">
        {PESTANAS.map((p) => (
          <button key={p.id} type="button" role="tab" id={`pestana-${p.id}`} aria-selected={p.id === activa.id} aria-controls="panel-catalogo" className={p.id === activa.id ? 'pestana activa' : 'pestana'} onClick={() => setParametros({ pestana: p.id }, { replace: true })}>
            {p.titulo}
          </button>
        ))}
      </div>
      <div id="panel-catalogo" role="tabpanel" aria-labelledby={`pestana-${activa.id}`}>
        <Contenido key={activa.id} />
      </div>
    </section>
  )
}