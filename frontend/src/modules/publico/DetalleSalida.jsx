import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { obtenerRuta } from '../../api/publico.js'
import FotoAgencia from '../../components/FotoAgencia.jsx'
import Mosaico, { ImagenAzul } from '../../components/Mosaico.jsx'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { useAgencias } from '../../hooks/useAgencias.js'
import { fechaHoraBreve } from '../../utils/formato.js'

const ciudad = (nombre) => nombre.replace(/^Agencia\s+/i, '').split(' ')[0]

// Diapositiva 5 del prototipo: una ruta con sus precios, su horario habitual y sus salidas recientes.
export default function DetalleSalida() {
  const { id } = useParams()
  const [estado, setEstado] = useState({ fase: 'cargando', ruta: null, mensaje: '' })
  const [intento, setIntento] = useState(0)
  const { porCodigo } = useAgencias()

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
  const agOrigen = porCodigo.get(origen)
  const agDestino = porCodigo.get(destino)

  return (
    <section className="detalle-salida">
      <div className="cabecera-ilustrada">
        <div>
          <h1>
            {ciudad(r.origen.nombre)} → {ciudad(r.destino.nombre)}
          </h1>
          <p className="ruta-texto">
            {r.origen.nombre} ({origen}) → {r.destino.nombre} ({destino})
          </p>
        </div>
        <Mosaico ids={[50, 23, 17, 61]} />
      </div>

      {(agOrigen || agDestino) && (
        <div className="fotos-ruta">
          <FotoAgencia agencia={agOrigen} etiqueta="Origen" />
          <span className="fotos-ruta-flecha" aria-hidden="true">
            →
          </span>
          <FotoAgencia agencia={agDestino} etiqueta="Destino" />
        </div>
      )}

      <div className="datos-iconos">
        {[
          [11, 'Distancia', `${r.distanciaKm} km`],
          [9, 'Tiempo estimado', `${r.horas} h`],
          [10, 'Salida frecuente', r.horarios.length > 0 ? r.horarios.join(' y ') : '—'],
          [
            3,
            'Llegada aproximada',
            r.llegadaAproximada
              ? r.llegadaAproximada.desde === r.llegadaAproximada.hasta
                ? r.llegadaAproximada.desde
                : `${r.llegadaAproximada.desde} – ${r.llegadaAproximada.hasta}`
              : '—',
          ],
        ].map(([icono, etiqueta, valor]) => (
          <div key={etiqueta} className="dato-icono">
            <span className="celda-azul tipo-icono">
              <ImagenAzul id={icono} />
            </span>
            <div>
              <span className="etiqueta-dato">{etiqueta}</span>
              <strong>{valor}</strong>
            </div>
          </div>
        ))}
      </div>

      <article className="panel llega-a-tiempo">
        <h2>Precio por bulto</h2>
        <ul className="datos-iconos" aria-label="Precios por tipo de envío">
          {r.tarifas.map((t) => (
            <li key={t.tipo} className="dato-icono">
              <span className="celda-azul tipo-icono">
                <ImagenAzul id={t.tipo === 'SOBRE' ? 4 : t.tipo === 'PAQUETE' ? 1 : 2} />
              </span>
              <div>
                <span className="etiqueta-dato">{t.tipo === 'SOBRE' ? 'Sobre' : t.tipo === 'PAQUETE' ? 'Paquete' : 'Carga'}</span>
                <strong>desde S/ {t.precioPorBulto}</strong>
              </div>
            </li>
          ))}
        </ul>
        <p className="ayuda">
          {r.horarios.length > 0
            ? `Con un viaje de ${r.horas} h, una salida a la hora frecuente llegaría aproximadamente a la hora indicada. `
            : r.programado
              ? 'Hay un viaje programado, pero todavía no hay salidas registradas para dar una referencia de horarios. '
              : 'Todavía no hay salidas registradas en esta ruta, así que no hay una referencia de horarios. '}
          Es una referencia histórica, no una promesa: no asegura que haya una salida hoy ni que se admita tu paquete. Confirma la salida en la agencia.
        </p>
      </article>

      <h2>Salidas recientes (historial)</h2>
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
