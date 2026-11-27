import { useEffect, useState } from 'react'
import Mosaico from '../../components/Mosaico.jsx'
import { Link } from 'react-router-dom'
import { listarMisViajes } from '../../api/viajes.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import RutaMonedas from '../../components/RutaMonedas.jsx'
import { useAgencias } from '../../hooks/useAgencias.js'
import { fechaHoraBreve } from '../../utils/formato.js'

const FILTROS = [
  { valor: '', texto: 'Todos', vacio: 'Todavía no tienes viajes asignados.' },
  { valor: 'PROGRAMADO', texto: 'Programados', vacio: 'No tienes viajes programados.' },
  { valor: 'EN_RUTA', texto: 'En ruta', vacio: 'No tienes viajes en ruta.' },
  { valor: 'TERMINADO', texto: 'Terminados', vacio: 'No tienes viajes terminados.' },
]

function momento(viaje) {
  if (viaje.estado === 'TERMINADO') return `Llegó ${fechaHoraBreve(viaje.fechaHoraLlegada)}`
  if (viaje.estado === 'EN_RUTA') return `Salió ${fechaHoraBreve(viaje.fechaHoraSalida)}`
  return 'Sin salida aún'
}

// Diapositiva 24 del prototipo: los viajes del transportista, con filtro por estado.
export default function MisViajes() {
  const [filtro, setFiltro] = useState('')
  const [estado, setEstado] = useState({ fase: 'cargando', datos: null, mensaje: '' })
  const [intento, setIntento] = useState(0)
  const { porCodigo } = useAgencias()

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarMisViajes(filtro, control.signal)
      .then((datos) => setEstado({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [filtro, intento])

  const textoVacio = FILTROS.find((f) => f.valor === filtro)?.vacio

  return (
    <section className="mis-viajes">
      <div className="cabecera-ilustrada">
        <h1>Mis viajes</h1>
        <Mosaico ids={[61, 57, 2, 23]} />
      </div>

      <div className="barra-filtros">
        <div className="filtros" role="group" aria-label="Filtrar por estado">
          {FILTROS.map((f) => (
            <button key={f.valor} type="button" className={filtro === f.valor ? 'filtro activo' : 'filtro'} aria-pressed={filtro === f.valor} onClick={() => setFiltro(f.valor)}>
              {f.texto}
            </button>
          ))}
        </div>
      </div>

      {estado.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando tus viajes…
        </p>
      )}

      {estado.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar tus viajes.</strong> {estado.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}

      {estado.fase === 'listo' && estado.datos.viajes.length === 0 && (
        <div className="panel estado-vacio">
          <p>{textoVacio}</p>
        </div>
      )}

      {estado.fase === 'listo' && estado.datos.viajes.length > 0 && (
        <>
          <div className="tabla-envoltorio">
            <table className="tabla-reservas tabla-viajes">
              <caption className="solo-lectores">Mis viajes</caption>
              <thead>
                <tr>
                  <th scope="col">Viaje</th>
                  <th scope="col">Ruta</th>
                  <th scope="col">Momento</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Acción</th>
                </tr>
              </thead>
              <tbody>
                {estado.datos.viajes.map((v) => (
                  <tr key={v.codigo} className={`fila-viaje estado-${v.estado.toLowerCase()}`}>
                    <th scope="row">{v.codigo}</th>
                    <td data-etiqueta="Ruta">
                      <RutaMonedas
                        origen={porCodigo.get(v.origen.codigo)?.departamento ?? ''}
                        destino={porCodigo.get(v.destino.codigo)?.departamento ?? ''}
                        nombreOrigen={v.origen.nombre}
                        nombreDestino={v.destino.nombre}
                        tamano={36}
                      />
                      <span className="solo-lectores">
                        {v.origen.nombre} a {v.destino.nombre}
                      </span>
                    </td>
                    <td data-etiqueta="Momento">{momento(v)}</td>
                    <td data-etiqueta="Estado">
                      <InsigniaEstado estado={v.estado} />
                    </td>
                    <td className="celda-accion" data-etiqueta="Acción">
                      <Link to={`/transportista/viajes/${v.codigo}`} className={v.estado === 'TERMINADO' ? 'boton boton-claro' : 'boton boton-azul'}>
                        {v.estado === 'PROGRAMADO' ? 'Abrir · marcar salida' : v.estado === 'EN_RUTA' ? 'Abrir · marcar llegada' : 'Ver detalle'}
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {estado.datos.hayMas && <p className="ayuda">Se muestran tus 200 viajes más recientes.</p>}
        </>
      )}
    </section>
  )
}
