import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { marcarLlegada, marcarSalida, obtenerViaje } from '../../api/viajes.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import TablaCarga from '../../components/TablaCarga.jsx'
import { fechaHoraBreve, horaCorta, kilosSinCeros } from '../../utils/formato.js'

// Diapositiva 25 del prototipo: un viaje del transportista con su carga, y los botones «Marcar salida» y «Marcar llegada».
export default function DetalleViaje() {
  const { codigo } = useParams()
  const [estado, setEstado] = useState({ fase: 'cargando', viaje: null, mensaje: '' }) // cargando | listo | no_encontrado | error
  const [intento, setIntento] = useState(0)
  const [pendiente, setPendiente] = useState(null) // 'salida' | 'llegada': acción a la que se pide confirmar
  const [enviando, setEnviando] = useState(false)
  const [aviso, setAviso] = useState({ tipo: '', texto: '' })

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: anterior.viaje ? 'listo' : 'cargando' }))
    obtenerViaje(codigo, control.signal)
      .then((viaje) => setEstado({ fase: 'listo', viaje, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: fallo.estado === 404 ? 'no_encontrado' : 'error', viaje: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [codigo, intento])

  async function registrar(accion) {
    setEnviando(true)
    setAviso({ tipo: '', texto: '' })
    try {
      const viaje = await (accion === 'salida' ? marcarSalida(codigo) : marcarLlegada(codigo))
      setEstado({ fase: 'listo', viaje, mensaje: '' })
      setAviso({ tipo: 'ok', texto: accion === 'salida' ? 'Salida registrada. ¡Buen viaje!' : 'Llegada registrada. Los envíos quedaron disponibles en la agencia de destino.' })
    } catch (fallo) {
      setAviso({ tipo: 'error', texto: fallo.message })
      if (fallo.estado === 409 || fallo.estado === 404) setIntento((n) => n + 1) // el estado cambió: se vuelve a leer
    } finally {
      setEnviando(false)
      setPendiente(null)
    }
  }

  if (estado.fase === 'no_encontrado') {
    return (
      <section className="confirmacion panel">
        <h1>No encontramos ese viaje</h1>
        <p>{estado.mensaje}</p>
        <div className="acciones-confirmacion">
          <Link to="/transportista" className="boton boton-azul">
            Volver a Mis viajes
          </Link>
        </div>
      </section>
    )
  }
  if (estado.fase === 'error') {
    return (
      <div className="resultado error" role="alert">
        <strong>No se pudo cargar el viaje.</strong> {estado.mensaje}{' '}
        <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
          Reintentar
        </button>
      </div>
    )
  }
  if (!estado.viaje) {
    return (
      <p role="status" className="cargando">
        Cargando el viaje…
      </p>
    )
  }

  const v = estado.viaje
  const llegada = v.fechaHoraLlegada ? fechaHoraBreve(v.fechaHoraLlegada) : v.llegadaEstimada ? `— (estimada ${horaCorta(v.llegadaEstimada)})` : '—'

  return (
    <section className="detalle-viaje">
      <div className="detalle-viaje-cabecera">
        <h1>
          {v.codigo} · {v.origen.nombre} → {v.destino.nombre}
        </h1>
        <InsigniaEstado estado={v.estado} />
      </div>

      <dl className="datos-viaje panel">
        <div>
          <dt>Vehículo</dt>
          <dd>
            {v.vehiculo.tipo} {v.vehiculo.placa}
          </dd>
        </div>
        <div>
          <dt>Salida</dt>
          <dd>{v.fechaHoraSalida ? fechaHoraBreve(v.fechaHoraSalida) : '—'}</dd>
        </div>
        <div>
          <dt>Llegada</dt>
          <dd>{llegada}</dd>
        </div>
      </dl>

      <h2>Carga</h2>
      {v.carga.length === 0 ? (
        <div className="panel estado-vacio">
          <p>Este viaje no tiene envíos cargados.</p>
        </div>
      ) : (
        <TablaCarga carga={v.carga} />
      )}
      <p className="peso-cargado">
        Peso cargado <strong>{v.pesoCargadoKg}</strong> de <strong>{kilosSinCeros(v.vehiculo.capacidadKg)}</strong> kg
      </p>

      {aviso.texto && (
        <div className={`resultado ${aviso.tipo === 'ok' ? 'ok' : 'error'}`} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
          {aviso.texto}
        </div>
      )}

      {pendiente ? (
        <div className="confirmar-viaje panel" role="group" aria-label={`Confirmar la ${pendiente}`}>
          <p>
            {pendiente === 'salida' ? '¿Marcar la salida ahora? Quedará registrada con la hora actual y no se puede deshacer.' : '¿Marcar la llegada ahora? Quedará registrada con la hora actual y no se puede deshacer.'}
            {pendiente === 'salida' && v.cantidadEnvios === 0 ? ' Este viaje sale sin carga.' : ''}
          </p>
          <div className="acciones-formulario">
            <button type="button" className="boton boton-azul" onClick={() => registrar(pendiente)} disabled={enviando}>
              {enviando ? 'Registrando…' : 'Sí, marcar'}
            </button>
            <button type="button" className="boton boton-claro" onClick={() => setPendiente(null)} disabled={enviando}>
              No
            </button>
          </div>
        </div>
      ) : (
        <div className="acciones-formulario">
          <button type="button" className="boton boton-azul" disabled={v.estado !== 'PROGRAMADO'} onClick={() => setPendiente('salida')}>
            Marcar salida
          </button>
          <button type="button" className="boton boton-marino" disabled={v.estado !== 'EN_RUTA'} onClick={() => setPendiente('llegada')}>
            Marcar llegada
          </button>
          <Link to="/transportista" className="boton boton-claro">
            Mis viajes
          </Link>
        </div>
      )}
    </section>
  )
}
