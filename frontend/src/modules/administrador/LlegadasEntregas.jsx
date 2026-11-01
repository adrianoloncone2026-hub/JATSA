import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { confirmarEntrega, listarPendientes, marcarDevuelto, obtenerEnvioParaEntrega } from '../../api/entregas.js'
import { useAuth } from '../../auth/AuthProvider.jsx'
import CampoTexto from '../../components/CampoTexto.jsx'
import { fechaHoraBreve, horaCorta } from '../../utils/formato.js'
import { errorDocumentoPersonal, sinSeparadores } from '../../utils/validaciones.js'

const TIPOS = ['DNI', 'RUC', 'CE', 'PAS']

// Como en el servidor: sin espacios ni guiones; CE y PAS en mayúsculas.
const normalizar = (tipo, numero) => (tipo === 'CE' || tipo === 'PAS' ? sinSeparadores(numero).toUpperCase() : sinSeparadores(numero))

// Diapositiva 22 del prototipo: viajes en camino a la agencia, envíos listos para entregar y la entrega contra el documento.
export default function LlegadasEntregas() {
  const { usuario } = useAuth()
  const [pendientes, setPendientes] = useState({ fase: 'cargando', datos: null, mensaje: '' }) // cargando | listo | error
  const [intento, setIntento] = useState(0)
  const [aviso, setAviso] = useState({ tipo: '', texto: '' })
  const [atendiendo, setAtendiendo] = useState(null) // código del envío abierto en el panel
  const [detalle, setDetalle] = useState({ fase: 'inactivo', envio: null, mensaje: '' }) // cargando | listo | no_encontrado | error
  const [formulario, setFormulario] = useState({ tipoDocumento: 'DNI', numeroDocumento: '' })
  const [errores, setErrores] = useState({})
  const [enviando, setEnviando] = useState(null) // 'entrega' | 'devolucion'
  const [confirmandoDevolucion, setConfirmandoDevolucion] = useState(false)

  useEffect(() => {
    const control = new AbortController()
    setPendientes((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarPendientes(control.signal)
      .then((datos) => setPendientes({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setPendientes({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  // El envío del panel: una atención nueva cancela la lectura anterior (una respuesta lenta no pisa a la actual).
  useEffect(() => {
    if (!atendiendo) return undefined
    const control = new AbortController()
    obtenerEnvioParaEntrega(atendiendo, control.signal)
      .then((envio) => setDetalle({ fase: 'listo', envio, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setDetalle({ fase: fallo.estado === 404 ? 'no_encontrado' : 'error', envio: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [atendiendo])

  function atender(codigo) {
    setAviso({ tipo: '', texto: '' })
    setErrores({})
    setConfirmandoDevolucion(false)
    setFormulario({ tipoDocumento: 'DNI', numeroDocumento: '' })
    setDetalle({ fase: 'cargando', envio: null, mensaje: '' })
    setAtendiendo(codigo)
  }

  function cerrarPanel() {
    setAtendiendo(null)
    setDetalle({ fase: 'inactivo', envio: null, mensaje: '' })
    setConfirmandoDevolucion(false)
  }

  function cambiar(campo, valor) {
    setFormulario((anterior) => ({ ...anterior, [campo]: valor }))
    if (errores[campo]) setErrores((anterior) => ({ ...anterior, [campo]: '' }))
  }

  const envio = detalle.envio
  const escrito = formulario.numeroDocumento.trim() !== ''
  const formatoValido = errorDocumentoPersonal(formulario.tipoDocumento, formulario.numeroDocumento) === ''
  const coincide = envio !== null && formatoValido && envio.destinatario.tipoDocumento === formulario.tipoDocumento && envio.destinatario.numeroDocumento === normalizar(formulario.tipoDocumento, formulario.numeroDocumento)
  const abierto = envio !== null && envio.estado === 'EN_DESTINO'
  const conSaldo = envio !== null && envio.saldo !== '0.00'

  async function entregar(evento) {
    evento.preventDefault()
    if (!envio || enviando) return
    const error = errorDocumentoPersonal(formulario.tipoDocumento, formulario.numeroDocumento)
    if (error) {
      setErrores({ numeroDocumento: error })
      document.getElementById('numeroDocumento')?.focus()
      return
    }
    setEnviando('entrega')
    setAviso({ tipo: '', texto: '' })
    try {
      const entregado = await confirmarEntrega(envio.codigoSeguimiento, formulario)
      setDetalle({ fase: 'listo', envio: entregado, mensaje: '' })
      setAviso({ tipo: 'ok', texto: `Envío ${entregado.codigoSeguimiento} entregado a ${entregado.destinatario.nombreCompleto} el ${fechaHoraBreve(entregado.fechaHoraEntrega)}.` })
      setIntento((n) => n + 1)
    } catch (fallo) {
      if (fallo.estado === 422 && Object.keys(fallo.campos ?? {}).length > 0) {
        setErrores(fallo.campos)
        document.getElementById(fallo.campos.tipoDocumento ? 'tipoDocumento' : 'numeroDocumento')?.focus()
      } else {
        setAviso({ tipo: 'error', texto: fallo.message })
        if (fallo.estado === 409 || fallo.estado === 404) {
          setIntento((n) => n + 1) // el estado cambió: se vuelven a leer las listas y el envío
          obtenerEnvioParaEntrega(envio.codigoSeguimiento).then((actual) => setDetalle({ fase: 'listo', envio: actual, mensaje: '' })).catch(() => {})
        }
      }
    } finally {
      setEnviando(null)
    }
  }

  async function devolver() {
    if (!envio || enviando) return
    setEnviando('devolucion')
    setAviso({ tipo: '', texto: '' })
    try {
      const devuelto = await marcarDevuelto(envio.codigoSeguimiento)
      setDetalle({ fase: 'listo', envio: devuelto, mensaje: '' })
      setAviso({ tipo: 'ok', texto: `Envío ${devuelto.codigoSeguimiento} marcado como devuelto.` })
      setIntento((n) => n + 1)
    } catch (fallo) {
      setAviso({ tipo: 'error', texto: fallo.message })
      if (fallo.estado === 409 || fallo.estado === 404) {
        setIntento((n) => n + 1)
        obtenerEnvioParaEntrega(envio.codigoSeguimiento).then((actual) => setDetalle({ fase: 'listo', envio: actual, mensaje: '' })).catch(() => {})
      }
    } finally {
      setEnviando(null)
      setConfirmandoDevolucion(false)
    }
  }

  const datos = pendientes.datos
  const nombreAgencia = datos?.sucursal.nombre ?? usuario?.sucursal?.nombre

  return (
    <section className="llegadas-entregas">
      <h1>Llegadas y entregas{nombreAgencia ? ` · ${nombreAgencia}` : ''}</h1>

      {aviso.texto && (
        <div className={`resultado ${aviso.tipo === 'ok' ? 'ok' : 'error'}`} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
          {aviso.texto}
        </div>
      )}

      {pendientes.fase === 'cargando' && !datos && (
        <p role="status" className="cargando">
          Cargando llegadas y entregas…
        </p>
      )}
      {pendientes.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar las llegadas y entregas.</strong> {pendientes.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}

      {datos && pendientes.fase !== 'error' && (
        <>
          <h2>En camino hacia aquí</h2>
          {datos.enCamino.length === 0 ? (
            <div className="panel estado-vacio">
              <p>No hay viajes en camino hacia esta agencia.</p>
            </div>
          ) : (
            <ul className="viajes-en-camino">
              {datos.enCamino.map((v) => (
                <li key={v.codigo} className="panel">
                  <strong>{v.codigo}</strong> {v.origen.nombre} → {v.destino.nombre}
                  {v.llegadaEstimada ? ` · llega aprox. ${horaCorta(v.llegadaEstimada)}` : ''} · {v.cantidadEnvios} {v.cantidadEnvios === 1 ? 'envío' : 'envíos'}
                </li>
              ))}
            </ul>
          )}

          <h2>Listos para entregar</h2>
          {datos.listos.length === 0 ? (
            <div className="panel estado-vacio">
              <p>No hay envíos listos para entregar en esta agencia.</p>
            </div>
          ) : (
            <div className="tabla-envoltorio">
              <table className="tabla-reservas tabla-listos">
                <caption className="solo-lectores">Envíos listos para entregar</caption>
                <thead>
                  <tr>
                    <th scope="col">Envío</th>
                    <th scope="col">Destinatario</th>
                    <th scope="col">Saldo</th>
                    <th scope="col">Acción</th>
                  </tr>
                </thead>
                <tbody>
                  {datos.listos.map((e) => (
                    <tr key={e.codigoSeguimiento} className={atendiendo === e.codigoSeguimiento ? 'fila-abierta' : undefined}>
                      <th scope="row">{e.codigoSeguimiento}</th>
                      <td data-etiqueta="Destinatario">
                        {e.destinatario}
                        {e.tipoEntrega === 'DOMICILIO' ? ' · a domicilio' : ''}
                      </td>
                      <td data-etiqueta="Saldo">S/ {e.saldo}</td>
                      <td className="celda-accion" data-etiqueta="Acción">
                        <button type="button" className="boton boton-azul" aria-expanded={atendiendo === e.codigoSeguimiento} onClick={() => atender(e.codigoSeguimiento)}>
                          Atender
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {datos.hayMas && <p className="ayuda">Se muestran los 200 envíos más antiguos.</p>}
        </>
      )}

      {atendiendo && (
        <section className="panel entrega" aria-label={`Entregar ${atendiendo}`}>
          {detalle.fase === 'cargando' && (
            <p role="status" className="cargando">
              Cargando el envío…
            </p>
          )}
          {detalle.fase === 'no_encontrado' && (
            <>
              <p className="resultado error" role="alert">
                {detalle.mensaje}
              </p>
              <button type="button" className="boton boton-claro" onClick={cerrarPanel}>
                Cerrar
              </button>
            </>
          )}
          {detalle.fase === 'error' && (
            <div className="resultado error" role="alert">
              <strong>No se pudo cargar el envío.</strong> {detalle.mensaje}{' '}
              <button type="button" className="boton boton-claro" onClick={() => atender(atendiendo)}>
                Reintentar
              </button>
            </div>
          )}

          {envio && (
            <>
              <div className="entrega-cabecera">
                <h2>
                  {envio.estado === 'ENTREGADO' ? 'Entregado' : envio.estado === 'DEVUELTO' ? 'Devuelto' : 'Entregar'} {envio.codigoSeguimiento}
                </h2>
                <span className={`insignia insignia-${envio.estado.toLowerCase()}`}>{envio.estado.replace('_', ' ')}</span>
                <button type="button" className="boton boton-claro" onClick={cerrarPanel}>
                  Cerrar
                </button>
              </div>

              <p>
                Destinatario registrado: <strong>{envio.destinatario.nombreCompleto}</strong> · {envio.destinatario.tipoDocumento} {envio.destinatario.numeroDocumento}
              </p>
              {envio.tipoEntrega === 'DOMICILIO' && <p className="ayuda">Entrega a domicilio: {envio.direccionEntrega}</p>}
              <p className="ayuda" aria-label="Resumen del envío">
                {envio.cantidadBultos} {envio.cantidadBultos === 1 ? 'bulto' : 'bultos'} ·{' '}
                {envio.cantidadBultos === 1 ? `${envio.bultos[0].descripcion} · ${envio.pesoTotalKg} kg` : `${envio.pesoTotalKg} kg`} · Pagado S/ {envio.pagado} · Saldo S/ {envio.saldo}
              </p>

              {envio.estado === 'ENTREGADO' && <p className="mensaje-aviso">Entregado el {fechaHoraBreve(envio.fechaHoraEntrega)}.</p>}
              {envio.estado === 'DEVUELTO' && <p className="mensaje-aviso">Este envío fue marcado como devuelto.</p>}

              {abierto && (
                <form className="formulario-entrega" onSubmit={entregar} noValidate>
                  <div className="fila-documento-entrega">
                    <CampoTexto id="tipoDocumento" etiqueta="Documento presentado" como="select" value={formulario.tipoDocumento} error={errores.tipoDocumento} onChange={(e) => cambiar('tipoDocumento', e.target.value)}>
                      {TIPOS.map((t) => (
                        <option key={t} value={t}>
                          {t}
                        </option>
                      ))}
                    </CampoTexto>
                    <CampoTexto id="numeroDocumento" etiqueta="Número" autoComplete="off" value={formulario.numeroDocumento} error={errores.numeroDocumento} onChange={(e) => cambiar('numeroDocumento', e.target.value)} />
                    <p className={escrito ? (coincide ? 'coincidencia coincide' : 'coincidencia no-coincide') : 'coincidencia'} role="status">
                      {escrito ? (coincide ? '✓ Coincide' : formatoValido ? '✗ No coincide' : '') : ''}
                    </p>
                  </div>

                  {conSaldo && <p className="mensaje-aviso">El envío tiene un saldo de S/ {envio.saldo}: cóbralo en Caja antes de entregarlo.</p>}

                  <div className="acciones-formulario">
                    <button type="submit" className="boton boton-azul" disabled={!coincide || conSaldo || enviando !== null}>
                      {enviando === 'entrega' ? 'Confirmando…' : 'Confirmar entrega'}
                    </button>
                    {conSaldo ? (
                      <Link to={`/administrador/caja?envio=${encodeURIComponent(envio.codigoSeguimiento)}`} className="boton boton-marino">
                        Cobrar saldo
                      </Link>
                    ) : (
                      <button type="button" className="boton boton-marino" disabled>
                        Cobrar saldo
                      </button>
                    )}
                    {confirmandoDevolucion ? (
                      <span className="confirmar-cancelacion" role="group" aria-label="Confirmar la devolución">
                        <span>¿Marcar devuelto?</span>
                        <button type="button" className="boton boton-marino" onClick={devolver} disabled={enviando !== null}>
                          {enviando === 'devolucion' ? 'Marcando…' : 'Sí, marcar'}
                        </button>
                        <button type="button" className="boton boton-claro" onClick={() => setConfirmandoDevolucion(false)} disabled={enviando !== null}>
                          No
                        </button>
                      </span>
                    ) : (
                      <button type="button" className="boton boton-claro" onClick={() => setConfirmandoDevolucion(true)} disabled={enviando !== null}>
                        Marcar devuelto
                      </button>
                    )}
                    <button type="button" className="boton boton-claro" disabled title="Solo disponible mientras el envío está REGISTRADO; no se puede desde la agencia de destino.">
                      Cambiar destino (solo REGISTRADO)
                    </button>
                  </div>
                  <p className="ayuda">Al confirmar se guarda la fecha y hora de entrega.</p>
                </form>
              )}
            </>
          )}
        </section>
      )}
    </section>
  )
}
