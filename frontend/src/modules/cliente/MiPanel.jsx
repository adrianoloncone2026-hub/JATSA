import { useEffect, useState } from 'react'
import Mosaico from '../../components/Mosaico.jsx'
import { Link } from 'react-router-dom'
import { obtenerResumenDeEnvios } from '../../api/historial.js'
import { obtenerRuta } from '../../api/publico.js'
import { listarReservas } from '../../api/reservas.js'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { ROLES } from '../../auth/roles.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import RutaMonedas from '../../components/RutaMonedas.jsx'
import { saludoDe } from '../../utils/formato.js'

// Diapositiva 11 del prototipo: envíos en curso, reservas pendientes, saldo por pagar, envíos por recoger y la reserva pendiente
export default function MiPanel() {
  const { usuario } = useAuth()
  const [estado, setEstado] = useState({ fase: 'cargando', pendientes: [], mensaje: '' })
  const [resumen, setResumen] = useState({ fase: 'cargando', datos: null, mensaje: '' })
  const [horarios, setHorarios] = useState([])
  const [intento, setIntento] = useState(0)

  useEffect(() => {
    const control = new AbortController()
    setResumen((anterior) => ({ ...anterior, fase: 'cargando' }))
    obtenerResumenDeEnvios(control.signal)
      .then((datos) => setResumen({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setResumen({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarReservas('PENDIENTE', control.signal)
      .then((pendientes) => setEstado({ fase: 'listo', pendientes, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: 'error', pendientes: [], mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  const masReciente = estado.pendientes[0]

  const rutaDeLaReserva = masReciente ? `${masReciente.origen.codigo}-${masReciente.destino.codigo}` : ''
  useEffect(() => {
    setHorarios([])
    if (!rutaDeLaReserva) return undefined
    const control = new AbortController()
    obtenerRuta(rutaDeLaReserva, control.signal)
      .then((ruta) => setHorarios(ruta.horarios))
      .catch(() => {})
    return () => control.abort()
  }, [rutaDeLaReserva])

  return (
    <section className="mi-panel">
      <div className="cabecera-ilustrada">
        <h1>Hola, {saludoDe(usuario.nombreCompleto, usuario.rol === ROLES.EMPRESARIO)}</h1>
        <Mosaico ids={[64, 1, 6, 23]} />
      </div>

      <div className="panel-resumen">
        <div className="indicadores">
          <div className="indicador">
            <strong>{resumen.fase === 'listo' ? resumen.datos.enCurso : '…'}</strong>
            <span>Envíos en curso</span>
          </div>
          <div className="indicador">
            <strong>{estado.fase === 'listo' ? estado.pendientes.length : '…'}</strong>
            <span>Reservas pendientes</span>
          </div>
          <div className="indicador">
            <strong>{resumen.fase === 'listo' ? `S/ ${resumen.datos.saldoPendiente}` : '…'}</strong>
            <span>Saldo pendiente de tus envíos</span>
          </div>
        </div>
        <div className="acciones-panel">
          <Link to="/cliente/nueva-reserva" className="boton boton-marino">
            Nueva reserva
          </Link>
          <Link to="/cliente/historial" className="boton boton-claro">
            Mis envíos
          </Link>
          {(usuario.rol === ROLES.CLIENTE || usuario.rol === ROLES.EMPRESARIO) && (
            <Link to="/cliente/cuenta" className="boton boton-claro">
              Mi cuenta
            </Link>
          )}
        </div>
      </div>

      {resumen.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudo cargar el resumen de tus envíos.</strong> {resumen.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}

      {resumen.fase === 'listo' && resumen.datos.porRecoger.length > 0 && (
        <>
          <h2>Por recoger</h2>
          <ul className="por-recoger">
            {resumen.datos.porRecoger.map((e) => (
              <li key={e.codigoSeguimiento} className="panel tarjeta-recoger" aria-label={`Por recoger ${e.codigoSeguimiento}`}>
                <h3>{e.codigoSeguimiento}</h3>
                <InsigniaEstado estado={e.estado} />
                <p>
                  De {e.de} · Listo para recoger en {e.agencia.nombre}, {e.agencia.direccion}. Lleva tu documento de identidad.
                </p>
                <Link to={`/cliente/historial?envio=${encodeURIComponent(e.codigoSeguimiento)}`} className="boton boton-azul">
                  Ver detalle
                </Link>
              </li>
            ))}
          </ul>
          {resumen.datos.hayMasPorRecoger && <p className="ayuda">Se muestran los 50 envíos más recientes por recoger. Revisa el resto en tu historial.</p>}
        </>
      )}

      <h2>Reserva pendiente</h2>
      {estado.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando tus reservas…
        </p>
      )}
      {estado.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar tus reservas.</strong> {estado.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {estado.fase === 'listo' && !masReciente && (
        <div className="panel estado-vacio">
          <p>No tienes reservas pendientes.</p>
          <Link to="/cliente/nueva-reserva" className="boton boton-azul">
            Hacer una reserva
          </Link>
        </div>
      )}
      {estado.fase === 'listo' && masReciente && (
        <article className="panel tarjeta-reserva" aria-label={`Reserva pendiente ${masReciente.codigo}`}>
          <RutaMonedas
            origen={masReciente.origen.departamento}
            destino={masReciente.destino.departamento}
            nombreOrigen={masReciente.origen.nombre}
            nombreDestino={masReciente.destino.nombre}
            tamano={72}
          />
          <h3>{masReciente.codigo}</h3>
          <InsigniaEstado estado={masReciente.estado} />
          <p>
            Para {masReciente.destinatario} · Lleva el paquete a {masReciente.origen.nombre}.{horarios.length > 0 ? ` Salió con más frecuencia a las ${horarios.join(' y ')} (referencia histórica).` : ''}
          </p>
          <div className="acciones-tarjeta">
            <Link to={`/cliente/reserva-registrada/${masReciente.codigo}`} className="boton boton-azul">
              Ver reserva
            </Link>
            <Link to={`/salidas/${rutaDeLaReserva}`} className="boton boton-claro">
              Horario
            </Link>
          </div>
          {estado.pendientes.length > 1 && (
            <p className="ayuda">
              Tienes {estado.pendientes.length} reservas pendientes. <Link to="/cliente/reservas">Ver todas</Link>
            </p>
          )}
        </article>
      )}
    </section>
  )
}
