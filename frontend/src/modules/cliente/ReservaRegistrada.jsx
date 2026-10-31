import { useEffect, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { obtenerReserva } from '../../api/reservas.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import RutaMonedas from '../../components/RutaMonedas.jsx'

const TITULOS = {
  PENDIENTE: 'Reserva registrada',
  FORMALIZADA: 'Reserva formalizada',
  CANCELADA: 'Reserva cancelada',
  VENCIDA: 'Reserva vencida',
}

function mensajeDe(reserva) {
  switch (reserva.estado) {
    case 'PENDIENTE':
      return `Lleva tu paquete a ${reserva.origen.nombre} (${reserva.origen.direccion}) y muestra este código. Si no lo llevas, la reserva vence.`
    case 'FORMALIZADA':
      return reserva.codigoSeguimiento
        ? `Tu paquete ya fue registrado en la agencia. Su código de seguimiento es ${reserva.codigoSeguimiento}.`
        : 'Tu paquete ya fue registrado en la agencia.'
    case 'CANCELADA':
      return 'Cancelaste esta reserva.'
    default:
      return 'Esta reserva venció porque el paquete no llegó a la agencia.'
  }
}

// Diapositiva 13 del prototipo: confirmación de la reserva con su código. La misma pantalla sirve para «Ver reserva».
export default function ReservaRegistrada() {
  const { codigo } = useParams()
  const ubicacion = useLocation()
  const [estado, setEstado] = useState({ fase: 'cargando', reserva: null, mensaje: '' })
  const [intento, setIntento] = useState(0)

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    obtenerReserva(codigo, control.signal)
      .then((reserva) => setEstado({ fase: 'listo', reserva, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: fallo.estado === 404 ? 'no_encontrada' : 'error', reserva: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [codigo, intento])

  // Recién creada: se muestra al instante con los datos de la creación mientras llega la versión del servidor.
  const reserva = estado.reserva ?? (ubicacion.state?.reserva?.codigo === codigo ? ubicacion.state.reserva : null)

  if (estado.fase === 'no_encontrada') {
    return (
      <section className="confirmacion panel">
        <h1>No encontramos esa reserva</h1>
        <p>{estado.mensaje}</p>
        <div className="acciones-confirmacion">
          <Link to="/cliente/reservas" className="boton boton-azul">
            Mis reservas
          </Link>
        </div>
      </section>
    )
  }

  if (estado.fase === 'error' && !reserva) {
    return (
      <div className="resultado error" role="alert">
        <strong>No se pudo cargar la reserva.</strong> {estado.mensaje}{' '}
        <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
          Reintentar
        </button>
      </div>
    )
  }

  if (!reserva) {
    return (
      <p role="status" className="cargando">
        Cargando la reserva…
      </p>
    )
  }

  return (
    <section className="confirmacion panel" aria-labelledby="titulo-reserva">
      <span className="sello" aria-hidden="true">
        ✓
      </span>
      <h1 id="titulo-reserva">{TITULOS[reserva.estado] ?? 'Reserva'}</h1>
      <p className="codigo-reserva">{reserva.codigo}</p>
      <RutaMonedas origen={reserva.origen.departamento} destino={reserva.destino.departamento} tamano={64} />
      <p className="ruta-texto">
        {reserva.origen.nombre} → {reserva.destino.nombre} · Para {reserva.destinatario}
      </p>
      {reserva.estado !== 'PENDIENTE' && <InsigniaEstado estado={reserva.estado} />}
      <p className="mensaje-confirmacion">{mensajeDe(reserva)}</p>
      <div className="acciones-confirmacion">
        <Link to="/cliente/reservas" className="boton boton-azul">
          Mis reservas
        </Link>
        <Link to="/cliente" className="boton boton-claro">
          Ir al panel
        </Link>
        <Link to="/salidas" className="boton boton-marino">
          Horarios
        </Link>
      </div>
    </section>
  )
}
