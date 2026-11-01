import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { listarMisEnvios, obtenerMiEnvio } from '../../api/historial.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { fechaCorta, fechaHoraBreve } from '../../utils/formato.js'

const ROLES = [
  { valor: '', texto: 'Envié y recibí' },
  { valor: 'ENVIE', texto: 'Envié' },
  { valor: 'RECIBI', texto: 'Recibí' },
]
const ESTADOS = ['REGISTRADO', 'EN_TRANSITO', 'EN_DESTINO', 'ENTREGADO', 'DEVUELTO', 'ANULADO']
const FILTROS_INICIALES = { q: '', rol: '', estado: '', desde: '', hasta: '' }

// "EN_DESTINO" → "EN DESTINO"
const etiqueta = (estado) => estado.replace('_', ' ')

// Diapositiva 14 del prototipo: los envíos que la persona envió o recibió, con filtros y el detalle de cada uno.
// El envío abierto va en la dirección (?envio=) para poder enlazarlo desde «Mi panel». De la otra parte solo se ve el nombre.
export default function Historial() {
  const [parametros, setParametros] = useSearchParams()
  const abierto = (parametros.get('envio') ?? '').toUpperCase()
  const [filtros, setFiltros] = useState(FILTROS_INICIALES)
  const [aplicados, setAplicados] = useState(FILTROS_INICIALES) // la búsqueda por texto espera un instante tras la última tecla
  const [lista, setLista] = useState({ fase: 'cargando', datos: null, mensaje: '', errores: {} }) // cargando | listo | error
  const [detalle, setDetalle] = useState({ fase: 'inactivo', envio: null, mensaje: '' }) // cargando | listo | no_encontrado | error
  const [intento, setIntento] = useState(0)

  useEffect(() => {
    const temporizador = setTimeout(() => setAplicados((actual) => (JSON.stringify(actual) === JSON.stringify(filtros) ? actual : filtros)), filtros.q === aplicados.q ? 0 : 350)
    return () => clearTimeout(temporizador)
  }, [filtros, aplicados.q])

  // Cada cambio de filtro cancela la consulta anterior: una respuesta lenta no pisa a la del filtro actual.
  useEffect(() => {
    const control = new AbortController()
    setLista((anterior) => ({ ...anterior, fase: 'cargando', errores: {} }))
    listarMisEnvios(aplicados, control.signal)
      .then((datos) => setLista({ fase: 'listo', datos, mensaje: '', errores: {} }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setLista({ fase: 'error', datos: null, mensaje: fallo.message, errores: fallo.campos ?? {} })
      })
    return () => control.abort()
  }, [aplicados, intento])

  useEffect(() => {
    if (!abierto) {
      setDetalle({ fase: 'inactivo', envio: null, mensaje: '' })
      return undefined
    }
    const control = new AbortController()
    setDetalle({ fase: 'cargando', envio: null, mensaje: '' })
    obtenerMiEnvio(abierto, control.signal)
      .then((envio) => setDetalle({ fase: 'listo', envio, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setDetalle({ fase: fallo.estado === 404 ? 'no_encontrado' : 'error', envio: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [abierto])

  const cambiar = (campo, valor) => setFiltros((anterior) => ({ ...anterior, [campo]: valor }))
  const abrir = (codigo) => setParametros(codigo ? { envio: codigo } : {}, { replace: true })
  const envios = lista.datos?.envios ?? []
  const e = detalle.envio
  const errores = lista.errores

  return (
    <section className="historial">
      <h1>Historial de envíos</h1>

      <div className="panel filtros-historial">
        <div className="campo">
          <label htmlFor="buscar">Buscar código o destinatario</label>
          <input id="buscar" type="search" autoComplete="off" maxLength={50} value={filtros.q} onChange={(ev) => cambiar('q', ev.target.value)} />
          {errores.q && <p className="campo-error">{errores.q}</p>}
        </div>
        <div className="campo">
          <label htmlFor="rol">Yo</label>
          <select id="rol" value={filtros.rol} onChange={(ev) => cambiar('rol', ev.target.value)}>
            {ROLES.map((r) => (
              <option key={r.valor} value={r.valor}>
                {r.texto}
              </option>
            ))}
          </select>
        </div>
        <div className="campo">
          <label htmlFor="estado">Estado</label>
          <select id="estado" value={filtros.estado} onChange={(ev) => cambiar('estado', ev.target.value)}>
            <option value="">Todos</option>
            {ESTADOS.map((s) => (
              <option key={s} value={s}>
                {etiqueta(s)}
              </option>
            ))}
          </select>
        </div>
        <div className="campo">
          <label htmlFor="desde">Desde</label>
          <input id="desde" type="date" value={filtros.desde} max={filtros.hasta || undefined} onChange={(ev) => cambiar('desde', ev.target.value)} />
          {errores.desde && <p className="campo-error">{errores.desde}</p>}
        </div>
        <div className="campo">
          <label htmlFor="hasta">Hasta</label>
          <input id="hasta" type="date" value={filtros.hasta} min={filtros.desde || undefined} onChange={(ev) => cambiar('hasta', ev.target.value)} />
          {errores.hasta && <p className="campo-error">{errores.hasta}</p>}
        </div>
      </div>

      <div className="barra-historial">
        <h2>Mis envíos</h2>
        <button type="button" className="boton boton-claro" disabled title="La exportación a Excel necesita una tecnología que todavía no está autorizada (módulo 12).">
          Exportar lista
        </button>
      </div>

      {lista.fase === 'cargando' && !lista.datos && (
        <p role="status" className="cargando">
          Cargando tus envíos…
        </p>
      )}
      {lista.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar tus envíos.</strong> {Object.keys(errores).length > 0 ? 'Revisa los filtros.' : lista.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {lista.fase === 'listo' && envios.length === 0 && (
        <div className="panel estado-vacio">
          <p>{JSON.stringify(aplicados) === JSON.stringify(FILTROS_INICIALES) ? 'Todavía no tienes envíos.' : 'No hay envíos que coincidan con esos filtros.'}</p>
          <Link to="/cliente/nueva-reserva" className="boton boton-azul">
            Hacer una reserva
          </Link>
        </div>
      )}
      {lista.fase === 'listo' && envios.length > 0 && (
        <>
          <ul className="lista-envios">
            {envios.map((x) => (
              <li key={x.codigoSeguimiento} className={abierto === x.codigoSeguimiento ? 'panel envio-fila abierta' : 'panel envio-fila'}>
                <strong>{x.codigoSeguimiento}</strong>
                <span className="envio-resumen">
                  {x.rol === 'ENVIE' ? 'Envié' : 'Recibí'} · {fechaCorta(x.fecha).slice(0, 5)} · S/ {x.importe}
                </span>
                <InsigniaEstado estado={x.estado} />
                <button type="button" className="boton boton-azul" aria-expanded={abierto === x.codigoSeguimiento} onClick={() => abrir(abierto === x.codigoSeguimiento ? '' : x.codigoSeguimiento)}>
                  Ver
                </button>
              </li>
            ))}
          </ul>
          {lista.datos.hayMas && <p className="ayuda">Se muestran tus 200 envíos más recientes: usa los filtros para acotar.</p>}
        </>
      )}

      {abierto && (
        <article className="panel detalle-envio" aria-label={`Detalle de ${abierto}`}>
          {detalle.fase === 'cargando' && (
            <p role="status" className="cargando">
              Cargando el detalle…
            </p>
          )}
          {detalle.fase === 'no_encontrado' && (
            <div className="resultado error" role="alert">
              {detalle.mensaje}
            </div>
          )}
          {detalle.fase === 'error' && (
            <div className="resultado error" role="alert">
              <strong>No se pudo cargar el detalle.</strong> {detalle.mensaje}
            </div>
          )}
          {e && (
            <>
              <div className="entrega-cabecera">
                <h2>{e.codigoSeguimiento}</h2>
                <InsigniaEstado estado={e.estado} />
                <button type="button" className="boton boton-claro" onClick={() => abrir('')}>
                  Cerrar
                </button>
              </div>
              <p>{e.rol === 'ENVIE' ? `Para ${e.otraParte}` : `De ${e.otraParte}`}</p>
              <p className="ayuda">
                {e.estado === 'ENTREGADO'
                  ? `Entregado ${fechaHoraBreve(e.fechaHoraEntrega)} en ${e.destino.nombre}`
                  : `${etiqueta(e.estado)} · ${e.origen.nombre} → ${e.destino.nombre}`}
              </p>
              {e.viaje && (
                <p className="ayuda">
                  Viaje {e.viaje.codigo}: {e.viaje.fechaHoraSalida ? `salió ${fechaHoraBreve(e.viaje.fechaHoraSalida)}` : 'todavía no sale'}
                  {e.viaje.fechaHoraLlegada ? `, llegó ${fechaHoraBreve(e.viaje.fechaHoraLlegada).slice(6)}` : ''}
                </p>
              )}
              <p className="ayuda">
                {e.pagos.filter((p) => p.estado === 'REGISTRADO').length > 0
                  ? e.pagos
                      .filter((p) => p.estado === 'REGISTRADO')
                      .map((p) => `Pagado S/ ${p.monto} · ${p.metodoPago} · ${p.sucursal}`)
                      .join(' · ')
                  : 'Sin pagos registrados'}
              </p>

              <h3>Bultos</h3>
              <div className="tabla-envoltorio">
                <table className="tabla-reservas tabla-bultos-historial">
                  <caption className="solo-lectores">Bultos del envío</caption>
                  <thead>
                    <tr>
                      <th scope="col">N°</th>
                      <th scope="col">Descripción</th>
                      <th scope="col">Peso</th>
                      <th scope="col">Tipo</th>
                      <th scope="col">Costo</th>
                    </tr>
                  </thead>
                  <tbody>
                    {e.bultos.map((b) => (
                      <tr key={b.numero}>
                        <th scope="row">{b.numero}</th>
                        <td data-etiqueta="Descripción">{b.descripcion}</td>
                        <td data-etiqueta="Peso">{b.pesoKg} kg</td>
                        <td data-etiqueta="Tipo">{b.tipo}</td>
                        <td data-etiqueta="Costo">S/ {b.costo}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="total-historial">
                Total S/ {e.importe} · Saldo S/ {e.saldo}
              </p>
              <div className="acciones-formulario">
                <Link to={`/rastreo?codigo=${encodeURIComponent(e.codigoSeguimiento)}`} className="boton boton-marino">
                  Rastreo público
                </Link>
                <Link to="/cliente/pagos" className="boton boton-claro">
                  Mis pagos
                </Link>
              </div>
            </>
          )}
        </article>
      )}
    </section>
  )
}
