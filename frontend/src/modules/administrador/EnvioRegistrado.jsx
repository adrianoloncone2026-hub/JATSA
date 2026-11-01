import { useEffect, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { obtenerEnvio } from '../../api/ventanilla.js'
import Etiqueta from '../../components/Etiqueta.jsx'
import { fechaHoraCorta } from '../../utils/formato.js'

// Confirmación tras formalizar (diapositiva 19): código de seguimiento, resumen de bultos y etiqueta para imprimir.
// La misma pantalla sirve para reabrir un envío y reimprimir su etiqueta.
export default function EnvioRegistrado() {
  const { codigo } = useParams()
  const ubicacion = useLocation()
  const [estado, setEstado] = useState({ fase: 'cargando', envio: null, mensaje: '' }) // cargando | listo | no_encontrado | error
  const [intento, setIntento] = useState(0)

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    obtenerEnvio(codigo, control.signal)
      .then((envio) => setEstado({ fase: 'listo', envio, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: fallo.estado === 404 ? 'no_encontrado' : 'error', envio: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [codigo, intento])

  // Recién formalizado: se muestra al instante con los datos del guardado mientras llega la versión del servidor.
  const envio = estado.envio ?? (ubicacion.state?.envio?.codigoSeguimiento === codigo ? ubicacion.state.envio : null)
  const cobrarAhora = ubicacion.state?.cobrarAhora === true

  if (estado.fase === 'no_encontrado') {
    return (
      <section className="confirmacion panel">
        <h1>No encontramos ese envío</h1>
        <p>{estado.mensaje}</p>
        <div className="acciones-confirmacion">
          <Link to="/administrador" className="boton boton-azul">
            Volver a Ventanilla
          </Link>
        </div>
      </section>
    )
  }
  if (estado.fase === 'error' && !envio) {
    return (
      <div className="resultado error" role="alert">
        <strong>No se pudo cargar el envío.</strong> {estado.mensaje}{' '}
        <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
          Reintentar
        </button>
      </div>
    )
  }
  if (!envio) {
    return (
      <p role="status" className="cargando">
        Cargando el envío…
      </p>
    )
  }

  return (
    <section className="envio-registrado">
      <div className="confirmacion panel no-imprimir" aria-labelledby="titulo-envio">
        <span className="sello" aria-hidden="true">
          ✓
        </span>
        <h1 id="titulo-envio">Envío registrado</h1>
        <p className="codigo-reserva">{envio.codigoSeguimiento}</p>
        <p className="ruta-texto">
          {envio.reserva.origen.nombre} → {envio.reserva.destino.nombre} · Para {envio.reserva.destinatario.nombreCompleto}
        </p>
        <p className="mensaje-confirmacion">
          {envio.cantidadBultos} {envio.cantidadBultos === 1 ? 'bulto' : 'bultos'} · {envio.pesoTotalKg} kg · S/ {envio.importe} · {envio.tipoEntrega === 'DOMICILIO' ? `entrega a domicilio (${envio.direccionEntrega})` : 'entrega en agencia'}
        </p>
        <p className="ayuda">
          Reserva {envio.reserva.codigo} formalizada · registrado el {fechaHoraCorta(envio.fechaHoraRegistro)}
        </p>
        <p className="mensaje-aviso" role="status">
          Envío guardado · pago pendiente. El cobro se registra en Caja, donde se elige quién paga; la modalidad «paga en destino» no se guarda.
        </p>
        <div className="acciones-confirmacion">
          <button type="button" className="boton boton-azul" onClick={() => window.print()}>
            Imprimir etiqueta
          </button>
          {/* «Cobrar ahora» abre Caja con el envío ya cargado (solo un parámetro de la URL: la modalidad no se guarda). */}
          <Link to={cobrarAhora ? `/administrador/caja?envio=${encodeURIComponent(envio.codigoSeguimiento)}` : '/administrador/caja'} className={cobrarAhora ? 'boton boton-marino' : 'boton boton-claro'}>
            {cobrarAhora ? 'Cobrar ahora (Caja)' : 'Ir a Caja'}
          </Link>
          <Link to="/administrador" className="boton boton-claro">
            Volver a Ventanilla
          </Link>
        </div>
      </div>

      <h2 className="no-imprimir">Etiqueta</h2>
      <Etiqueta envio={envio} />
    </section>
  )
}
