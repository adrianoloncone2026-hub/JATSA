import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { buscarReservaVentanilla, listarReservasPendientes } from '../../api/ventanilla.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { fechaHoraCorta } from '../../utils/formato.js'

const FORMATO_CODIGO = /^R\d{9}$/

const MOTIVO_NO_FORMALIZABLE = {
  FORMALIZADA: 'Esta reserva ya fue formalizada.',
  CANCELADA: 'Esta reserva fue cancelada: no se puede formalizar.',
  VENCIDA: 'Esta reserva venció: pasaron las 72 horas de vigencia.',
}

// Diapositiva 18 del prototipo: buscar una reserva por su código, ver las pendientes de la agencia y formalizar.
export default function Ventanilla() {
  const { usuario } = useAuth()
  const [codigo, setCodigo] = useState('')
  const [busqueda, setBusqueda] = useState({ fase: 'inactivo', reserva: null, mensaje: '' }) // inactivo | buscando | listo | no_encontrada | error
  const [pendientes, setPendientes] = useState({ fase: 'cargando', reservas: [], mensaje: '' })
  const [intento, setIntento] = useState(0)
  const controlBusqueda = useRef(null)

  // Pendientes de la agencia: se cargan al entrar y al reintentar.
  useEffect(() => {
    const control = new AbortController()
    setPendientes((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarReservasPendientes(control.signal)
      .then((reservas) => setPendientes({ fase: 'listo', reservas, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setPendientes({ fase: 'error', reservas: [], mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  // Una búsqueda nueva cancela la anterior: una respuesta lenta no pisa a la de la búsqueda actual.
  useEffect(() => () => controlBusqueda.current?.abort(), [])

  function buscar(evento) {
    evento.preventDefault()
    const limpio = codigo.trim().toUpperCase()
    controlBusqueda.current?.abort()
    if (!FORMATO_CODIGO.test(limpio)) {
      setBusqueda({ fase: 'no_encontrada', reserva: null, mensaje: 'El código de reserva es la letra R seguida de 9 dígitos (por ejemplo, R000000006).' })
      return
    }
    const control = new AbortController()
    controlBusqueda.current = control
    setBusqueda({ fase: 'buscando', reserva: null, mensaje: '' })
    buscarReservaVentanilla(limpio, control.signal)
      .then((reserva) => setBusqueda({ fase: 'listo', reserva, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setBusqueda({ fase: fallo.estado === 404 ? 'no_encontrada' : 'error', reserva: null, mensaje: fallo.message })
      })
  }

  const reserva = busqueda.reserva
  const formalizable = reserva && reserva.estado === 'PENDIENTE' && !reserva.plazoCumplido

  return (
    <section className="ventanilla">
      <h1>Ventanilla{usuario?.sucursal ? ` · ${usuario.sucursal.nombre}` : ''}</h1>

      <form className="panel buscar-reserva" onSubmit={buscar} noValidate>
        <div className="campo">
          <label htmlFor="codigoReserva">Código de reserva</label>
          <input id="codigoReserva" type="text" autoComplete="off" maxLength={10} placeholder="R000000006" value={codigo} onChange={(e) => setCodigo(e.target.value)} />
        </div>
        {/* No se deshabilita mientras busca: una búsqueda nueva cancela la anterior. */}
        <button type="submit" className="boton boton-azul">
          {busqueda.fase === 'buscando' ? 'Buscando…' : 'Buscar'}
        </button>
      </form>

      {busqueda.fase === 'no_encontrada' && (
        <div className="resultado error" role="alert">
          {busqueda.mensaje}
        </div>
      )}
      {busqueda.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudo buscar la reserva.</strong> {busqueda.mensaje}
        </div>
      )}

      {reserva && (
        <article className="panel reserva-encontrada" aria-label={`Reserva ${reserva.codigo}`}>
          <div className="reserva-encontrada-cabecera">
            <strong className="codigo-reserva-chico">{reserva.codigo}</strong>
            <InsigniaEstado estado={reserva.estado} />
            <span className="ruta-texto">
              {reserva.origen.nombre} → {reserva.destino.nombre}
            </span>
          </div>
          <p>
            Remitente: {reserva.remitente.nombreCompleto} · {reserva.remitente.tipoDocumento} {reserva.remitente.numeroDocumento}
          </p>
          <p>
            Destinatario: {reserva.destinatario.nombreCompleto} · {reserva.destinatario.tipoDocumento} {reserva.destinatario.numeroDocumento}
          </p>
          <p>
            Contenido: {reserva.contenido} · canal {reserva.canal} · {fechaHoraCorta(reserva.fechaHora)}
          </p>
          {formalizable && (
            <>
              <p className="ayuda">Vence el {fechaHoraCorta(reserva.venceEl)}.</p>
              <Link to={`/administrador/formalizar-envio/${reserva.codigo}`} className="boton boton-azul">
                Formalizar envío
              </Link>
            </>
          )}
          {!formalizable && (
            <p className="mensaje-aviso">
              {reserva.estado === 'PENDIENTE' ? 'Pasaron las 72 horas de vigencia: ya no se puede formalizar. Crea una nueva reserva.' : MOTIVO_NO_FORMALIZABLE[reserva.estado]}{' '}
              {reserva.codigoSeguimiento && (
                <Link to={`/administrador/envio/${reserva.codigoSeguimiento}`}>Ver el envío {reserva.codigoSeguimiento}</Link>
              )}
            </p>
          )}
        </article>
      )}

      <h2>Reservas pendientes para esta agencia</h2>

      {pendientes.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando las reservas pendientes…
        </p>
      )}
      {pendientes.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar las reservas.</strong> {pendientes.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {pendientes.fase === 'listo' && pendientes.reservas.length === 0 && (
        <div className="panel estado-vacio">
          <p>No hay reservas pendientes para esta agencia.</p>
        </div>
      )}
      {pendientes.fase === 'listo' && pendientes.reservas.length > 0 && (
        <div className="tabla-envoltorio">
          <table className="tabla-reservas">
            <caption className="solo-lectores">Reservas pendientes para esta agencia</caption>
            <thead>
              <tr>
                <th scope="col">Código</th>
                <th scope="col">Remitente</th>
                <th scope="col">Destino</th>
                <th scope="col">Vence</th>
                <th scope="col">Acción</th>
              </tr>
            </thead>
            <tbody>
              {pendientes.reservas.map((r) => (
                <tr key={r.codigo}>
                  <th scope="row">{r.codigo}</th>
                  <td data-etiqueta="Remitente">{r.remitente.nombreCompleto}</td>
                  <td data-etiqueta="Destino">{r.destino.nombre}</td>
                  <td data-etiqueta="Vence">{fechaHoraCorta(r.venceEl)}</td>
                  <td className="celda-accion" data-etiqueta="Acción">
                    <Link to={`/administrador/formalizar-envio/${r.codigo}`} className="boton boton-azul">
                      Formalizar
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="accesos-ventanilla" role="group" aria-label="Accesos de ventanilla">
        <Link to="/administrador/sin-reserva" className="boton boton-marino">
          Cliente sin reserva
        </Link>
        <Link to="/administrador/caja" className="boton boton-claro">
          Cobrar
        </Link>
        <Link to="/administrador/despacho" className="boton boton-claro">
          Despachar
        </Link>
        <Link to="/administrador/llegadas-entregas" className="boton boton-claro">
          Entregar
        </Link>
        <Link to="/salidas" className="boton boton-claro">
          Ver salidas
        </Link>
      </div>
    </section>
  )
}
