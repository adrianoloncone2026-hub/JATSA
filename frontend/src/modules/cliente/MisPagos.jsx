import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { listarMisPagos } from '../../api/pagos.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { fechaHoraBreve } from '../../utils/formato.js'

// Diapositiva 16 del prototipo: los pagos que hizo el cliente o empresario y el total pagado.
export default function MisPagos() {
  const [estado, setEstado] = useState({ fase: 'cargando', datos: null, mensaje: '' }) // cargando | listo | error
  const [intento, setIntento] = useState(0)

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarMisPagos(control.signal)
      .then((datos) => setEstado({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  const datos = estado.datos

  return (
    <section className="mis-pagos">
      <h1>Mis pagos</h1>

      {estado.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando tus pagos…
        </p>
      )}

      {estado.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar tus pagos.</strong> {estado.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}

      {estado.fase === 'listo' && datos.pagos.length === 0 && (
        <div className="panel estado-vacio">
          <p>Todavía no tienes pagos registrados.</p>
          <Link to="/cliente/nueva-reserva" className="boton boton-azul">
            Hacer una reserva
          </Link>
        </div>
      )}

      {estado.fase === 'listo' && datos.pagos.length > 0 && (
        <>
          <div className="tabla-envoltorio">
            <table className="tabla-reservas tabla-pagos">
              <caption className="solo-lectores">Mis pagos</caption>
              <thead>
                <tr>
                  <th scope="col">Fecha</th>
                  <th scope="col">Envío</th>
                  <th scope="col">Monto</th>
                  <th scope="col">Método</th>
                  <th scope="col">Pagado por</th>
                  <th scope="col">Sucursal</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Acción</th>
                </tr>
              </thead>
              <tbody>
                {datos.pagos.map((pago) => (
                  <tr key={pago.id}>
                    <th scope="row">{fechaHoraBreve(pago.fechaHora)}</th>
                    <td data-etiqueta="Envío">{pago.codigoSeguimiento}</td>
                    <td data-etiqueta="Monto">S/ {pago.monto}</td>
                    <td data-etiqueta="Método">{pago.metodoPago}</td>
                    <td data-etiqueta="Pagado por">{pago.pagadoPor}</td>
                    <td data-etiqueta="Sucursal">{pago.sucursal.nombre}</td>
                    <td data-etiqueta="Estado">
                      <InsigniaEstado estado={pago.estado} />
                    </td>
                    <td className="celda-accion" data-etiqueta="Acción">
                      <Link to={`/rastreo?codigo=${encodeURIComponent(pago.codigoSeguimiento)}`} className="boton boton-claro">
                        Ver envío
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {datos.hayMas && <p className="ayuda">Se muestran tus {datos.pagos.length} pagos más recientes; el total incluye todos.</p>}
          <p className="total-pagado">
            Total pagado <strong>S/ {datos.totalPagado}</strong>
          </p>
        </>
      )}
    </section>
  )
}
