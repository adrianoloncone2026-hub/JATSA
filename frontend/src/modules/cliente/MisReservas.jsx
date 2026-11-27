import { useEffect, useState } from 'react'
import Mosaico from '../../components/Mosaico.jsx'
import { Link } from 'react-router-dom'
import { cancelarReserva, listarReservas } from '../../api/reservas.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { fechaCorta } from '../../utils/formato.js'

const FILTROS = [
  { valor: '', texto: 'Todas', vacio: 'No tienes reservas todavía.' },
  { valor: 'PENDIENTE', texto: 'Pendientes', vacio: 'No tienes reservas pendientes.' },
  { valor: 'FORMALIZADA', texto: 'Formalizadas', vacio: 'No tienes reservas formalizadas.' },
  { valor: 'CANCELADA', texto: 'Canceladas', vacio: 'No tienes reservas canceladas.' },
  { valor: 'VENCIDA', texto: 'Vencidas', vacio: 'No tienes reservas vencidas.' },
]

// Diapositiva 15 del prototipo: reservas del cliente con filtro por estado y cancelación de las pendientes.
export default function MisReservas() {
  const [filtro, setFiltro] = useState('')
  const [estado, setEstado] = useState({ fase: 'cargando', reservas: [], mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [confirmando, setConfirmando] = useState(null)
  const [cancelando, setCancelando] = useState(null)
  const [aviso, setAviso] = useState({ tipo: '', texto: '' })

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarReservas(filtro, control.signal)
      .then((reservas) => setEstado({ fase: 'listo', reservas, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: 'error', reservas: [], mensaje: fallo.message })
      })
    return () => control.abort()
  }, [filtro, intento])

  function elegirFiltro(valor) {
    setConfirmando(null)
    setAviso({ tipo: '', texto: '' })
    setFiltro(valor)
  }

  async function cancelar(codigo) {
    setCancelando(codigo)
    setAviso({ tipo: '', texto: '' })
    try {
      const cancelada = await cancelarReserva(codigo)
      setEstado((anterior) => ({
        ...anterior,
        reservas: anterior.reservas.flatMap((r) => (r.codigo !== codigo ? [r] : filtro === 'PENDIENTE' ? [] : [cancelada])),
      }))
      setAviso({ tipo: 'ok', texto: `Reserva ${codigo} cancelada.` })
    } catch (fallo) {
      setAviso({ tipo: 'error', texto: fallo.message })
      if (fallo.estado === 409 || fallo.estado === 404) setIntento((n) => n + 1)
    } finally {
      setCancelando(null)
      setConfirmando(null)
    }
  }

  const textoVacio = FILTROS.find((f) => f.valor === filtro)?.vacio

  return (
    <section className="mis-reservas">
      <div className="cabecera-ilustrada">
        <h1>Mis reservas</h1>
        <Mosaico ids={[24, 1, 10, 62]} />
      </div>

      <div className="barra-filtros">
        <div className="filtros" role="group" aria-label="Filtrar por estado">
          {FILTROS.map((f) => (
            <button key={f.valor} type="button" className={filtro === f.valor ? 'filtro activo' : 'filtro'} aria-pressed={filtro === f.valor} onClick={() => elegirFiltro(f.valor)}>
              {f.texto}
            </button>
          ))}
        </div>
        <Link to="/cliente/nueva-reserva" className="boton boton-marino">
          Nueva reserva
        </Link>
      </div>

      {aviso.texto && (
        <div className={`resultado ${aviso.tipo === 'ok' ? 'ok' : 'error'}`} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
          {aviso.texto}
        </div>
      )}

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

      {estado.fase === 'listo' && estado.reservas.length === 0 && (
        <div className="panel estado-vacio">
          <p>{textoVacio}</p>
          <Link to="/cliente/nueva-reserva" className="boton boton-azul">
            Hacer una reserva
          </Link>
        </div>
      )}

      {estado.fase === 'listo' && estado.reservas.length > 0 && (
        <div className="tabla-envoltorio">
          <table className="tabla-reservas">
            <caption className="solo-lectores">Mis reservas</caption>
            <thead>
              <tr>
                <th scope="col">Código</th>
                <th scope="col">Fecha</th>
                <th scope="col">Canal</th>
                <th scope="col">Destinatario</th>
                <th scope="col">Ruta</th>
                <th scope="col">Estado</th>
                <th scope="col">Acción</th>
              </tr>
            </thead>
            <tbody>
              {estado.reservas.map((reserva) => (
                <tr key={reserva.codigo}>
                  <th scope="row">
                    <Link to={`/cliente/reserva-registrada/${reserva.codigo}`}>{reserva.codigo}</Link>
                  </th>
                  <td data-etiqueta="Fecha">{fechaCorta(reserva.fecha)}</td>
                  <td data-etiqueta="Canal">{reserva.canal}</td>
                  <td data-etiqueta="Destinatario">{reserva.destinatario}</td>
                  <td data-etiqueta="Ruta">
                    <span className="ruta-nombres">
                      <strong>{reserva.origen.nombre}</strong>
                      <span aria-hidden="true"> → </span>
                      <span className="solo-lectores"> a </span>
                      <strong>{reserva.destino.nombre}</strong>
                    </span>
                    <span className="ruta-regiones">
                      {reserva.origen.departamento} → {reserva.destino.departamento}
                    </span>
                  </td>
                  <td data-etiqueta="Estado">
                    <InsigniaEstado estado={reserva.estado} />
                  </td>
                  <td className="celda-accion" data-etiqueta="Acción">
                    {reserva.estado === 'PENDIENTE' && reserva.plazoCumplido && <span className="ayuda">Plazo cumplido</span>}
                    {reserva.estado === 'PENDIENTE' && !reserva.plazoCumplido && confirmando !== reserva.codigo && (
                      <button type="button" className="boton boton-claro" onClick={() => setConfirmando(reserva.codigo)}>
                        Cancelar reserva
                      </button>
                    )}
                    {reserva.estado === 'PENDIENTE' && !reserva.plazoCumplido && confirmando === reserva.codigo && (
                      <div className="confirmar-cancelacion" role="group" aria-label={`Confirmar la cancelación de ${reserva.codigo}`}>
                        <span>¿Cancelar {reserva.codigo}?</span>
                        <button type="button" className="boton boton-marino" onClick={() => cancelar(reserva.codigo)} disabled={cancelando === reserva.codigo}>
                          {cancelando === reserva.codigo ? 'Cancelando…' : 'Sí, cancelar'}
                        </button>
                        <button type="button" className="boton boton-claro" onClick={() => setConfirmando(null)} disabled={cancelando === reserva.codigo}>
                          No
                        </button>
                      </div>
                    )}
                    {reserva.estado === 'FORMALIZADA' &&
                      (reserva.codigoSeguimiento ? (
                        <Link to={`/rastreo?codigo=${encodeURIComponent(reserva.codigoSeguimiento)}`} className="boton boton-azul">
                          Ver envío
                        </Link>
                      ) : (
                        <Link to={`/cliente/reserva-registrada/${reserva.codigo}`} className="boton boton-claro">
                          Ver reserva
                        </Link>
                      ))}
                    {(reserva.estado === 'CANCELADA' || reserva.estado === 'VENCIDA') && (
                      <Link to={`/cliente/reserva-registrada/${reserva.codigo}`} className="boton boton-claro">
                        Ver reserva
                      </Link>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
