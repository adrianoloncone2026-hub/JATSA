import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { listarReservas } from '../../api/reservas.js'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { ROLES } from '../../auth/roles.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import RutaMonedas from '../../components/RutaMonedas.jsx'
import { saludoDe } from '../../utils/formato.js'

// Diapositiva 11 del prototipo, parte de reservas. «Envíos en curso», «Saldo por pagar» y «Por recoger»
// llegarán con los módulos de pagos y de rastreo e historial (6 y 9), que aportan esos datos.
export default function MiPanel() {
  const { usuario } = useAuth()
  const [estado, setEstado] = useState({ fase: 'cargando', pendientes: [], mensaje: '' })
  const [intento, setIntento] = useState(0)

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

  return (
    <section className="mi-panel">
      <h1>Hola, {saludoDe(usuario.nombreCompleto, usuario.rol === ROLES.EMPRESARIO)}</h1>

      <div className="panel-resumen">
        <div className="indicadores">
          <div className="indicador">
            <strong>{estado.fase === 'listo' ? estado.pendientes.length : '…'}</strong>
            <span>Reservas pendientes</span>
          </div>
        </div>
        <div className="acciones-panel">
          <Link to="/cliente/nueva-reserva" className="boton boton-marino">
            Nueva reserva
          </Link>
          <Link to="/rastreo" className="boton boton-claro">
            Rastrear un envío
          </Link>
          <Link to="/salidas" className="boton boton-claro">
            Ver salidas
          </Link>
        </div>
      </div>

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
          <RutaMonedas origen={masReciente.origen.departamento} destino={masReciente.destino.departamento} tamano={72} />
          <h3>{masReciente.codigo}</h3>
          <InsigniaEstado estado={masReciente.estado} />
          <p>
            Para {masReciente.destinatario} · Lleva el paquete a {masReciente.origen.nombre}.
          </p>
          <div className="acciones-tarjeta">
            <Link to={`/cliente/reserva-registrada/${masReciente.codigo}`} className="boton boton-azul">
              Ver reserva
            </Link>
            <Link to="/salidas" className="boton boton-claro">
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
