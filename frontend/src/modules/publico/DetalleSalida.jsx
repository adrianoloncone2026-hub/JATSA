import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { obtenerRuta } from '../../api/publico.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { fechaHoraBreve } from '../../utils/formato.js'

// "Agencia Arequipa Centro" → "Arequipa"
const ciudad = (nombre) => nombre.replace(/^Agencia\s+/i, '').split(' ')[0]

// Diapositiva 5 del prototipo: una ruta con sus precios, su horario habitual y sus salidas recientes.
export default function DetalleSalida() {
  const { id } = useParams()
  const [estado, setEstado] = useState({ fase: 'cargando', ruta: null, mensaje: '' }) // cargando | listo | no_encontrada | error
  const [intento, setIntento] = useState(0)

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    obtenerRuta(id, control.signal)
      .then((ruta) => setEstado({ fase: 'listo', ruta, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: fallo.estado === 404 ? 'no_encontrada' : 'error', ruta: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [id, intento])

  if (estado.fase === 'no_encontrada') {
    return (
      <section className="confirmacion panel">
        <h1>No encontramos esa ruta</h1>
        <p>{estado.mensaje}</p>
        <div className="acciones-confirmacion">
          <Link to="/salidas" className="boton boton-azul">
            Volver a salidas
          </Link>
        </div>
      </section>
    )
  }
  if (estado.fase === 'error') {
    return (
      <div className="resultado error" role="alert">
        <strong>No se pudo cargar la ruta.</strong> {estado.mensaje}{' '}
        <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
          Reintentar
        </button>
      </div>
    )
  }
  if (!estado.ruta) {
    return (
      <p role="status" className="cargando">
        Cargando la ruta…
      </p>
    )
  }

  const r = estado.ruta
  const [origen, destino] = [r.origen.codigo, r.destino.codigo]
  const precios = r.tarifas.map((t) => `S/ ${t.precioPorBulto} (${t.tipo === 'SOBRE' ? 'sobre' : t.tipo === 'PAQUETE' ? 'paquete' : 'carga'})`)
  const primera = r.horarios[0]

  return (
    <section className="detalle-salida">
      <h1>
        {ciudad(r.origen.nombre)} → {ciudad(r.destino.nombre)}
      </h1>
      <p className="ruta-texto">
        {r.distanciaKm} km · {r.horas} h estimadas · {r.origen.nombre} ({origen}) → {r.destino.nombre} ({destino})
      </p>

      <article className="panel llega-a-tiempo">
        <h2>¿Llega a tiempo?</h2>
        {r.horarios.length > 0 ? (
          <p>
            Sale habitualmente {r.horarios.join(' y ')}.
            {r.llegadaAproximada &&
              ` Si dejas tu paquete hoy antes de las ${primera}, llegaría aprox. a las ${r.llegadaAproximada.desde === r.llegadaAproximada.hasta ? r.llegadaAproximada.desde : `${r.llegadaAproximada.desde}–${r.llegadaAproximada.hasta}`}.`}
          </p>
        ) : (
          <p>{r.programado ? 'Hay un viaje programado, pero todavía no hay salidas registradas para calcular el horario habitual.' : 'Todavía no hay salidas registradas en esta ruta.'}</p>
        )}
        <p className="precios-ruta">Desde {precios.join(' · ')}</p>
      </article>

      <h2>Salidas recientes</h2>
      {r.recientes.length === 0 ? (
        <div className="panel estado-vacio">
          <p>Todavía no hay viajes que hayan salido en esta ruta.</p>
        </div>
      ) : (
        <div className="tabla-envoltorio">
          <table className="tabla-reservas tabla-salidas">
            <caption className="solo-lectores">Salidas recientes de la ruta</caption>
            <thead>
              <tr>
                <th scope="col">Viaje</th>
                <th scope="col">Salida</th>
                <th scope="col">Llegada</th>
                <th scope="col">Estado</th>
              </tr>
            </thead>
            <tbody>
              {r.recientes.map((v) => (
                <tr key={v.codigo}>
                  <th scope="row">{v.codigo}</th>
                  <td data-etiqueta="Salida">{fechaHoraBreve(v.fechaHoraSalida)}</td>
                  <td data-etiqueta="Llegada">{v.fechaHoraLlegada ? fechaHoraBreve(v.fechaHoraLlegada) : '—'}</td>
                  <td data-etiqueta="Estado">
                    <InsigniaEstado estado={v.estado} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="acciones-formulario">
        <Link to={`/cliente/nueva-reserva?origen=${origen}&destino=${destino}`} className="boton boton-azul">
          Reservar este envío
        </Link>
        <Link to={`/cotizador?origen=${origen}&destino=${destino}`} className="boton boton-marino">
          Cotizar
        </Link>
        <Link to={`/agencias?agencia=${origen}`} className="boton boton-claro">
          Agencia de origen
        </Link>
        <Link to="/rastreo" className="boton boton-claro">
          Rastrear un envío
        </Link>
        <Link to="/salidas" className="boton boton-claro">
          Volver a salidas
        </Link>
      </div>
    </section>
  )
}
