import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { anularPago, listarPagosCobrados, obtenerEnvioParaCobro, registrarPago } from '../../api/pagos.js'
import { useAuth } from '../../auth/AuthProvider.jsx'
import CampoTexto from '../../components/CampoTexto.jsx'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { fechaHoraBreve } from '../../utils/formato.js'

const FORMATO_CODIGO = /^EN[A-Z0-9]{10}$/
const FORMATO_MONTO = /^\d{1,8}([.,]\d{1,2})?$/
const METODOS = ['EFECTIVO', 'TARJETA', 'BILLETERA', 'TRANSFERENCIA']
const PAGADORES = ['REMITENTE', 'DESTINATARIO']
const NOMBRE_METODO = { EFECTIVO: 'Efectivo', TARJETA: 'Tarjeta', BILLETERA: 'Billetera', TRANSFERENCIA: 'Transferencia' }

// "23.75" o "23,75" → 2375 (céntimos enteros: sin errores de coma flotante).
function enCentimos(texto) {
  const [enteros, decimales = ''] = texto.trim().replace(',', '.').split('.')
  return Number(enteros) * 100 + Number(decimales.padEnd(2, '0'))
}

// Diapositiva 20 del prototipo: registrar el pago de un envío y ver lo cobrado en esta caja.
// La modalidad «cobrar ahora» / «paga en destino» no se guarda: todo envío con saldo está pendiente y aquí se elige quién paga.
export default function Caja() {
  const { usuario } = useAuth()
  const [parametros] = useSearchParams()
  const codigoInicial = (parametros.get('envio') ?? '').trim().toUpperCase()

  const [codigo, setCodigo] = useState(codigoInicial)
  const [busqueda, setBusqueda] = useState({ fase: 'inactivo', envio: null, mensaje: '' }) // inactivo | buscando | listo | no_encontrado | error
  const [formulario, setFormulario] = useState({ monto: '', pagadoPor: 'REMITENTE', metodoPago: 'EFECTIVO' })
  const [errores, setErrores] = useState({})
  const [registrando, setRegistrando] = useState(false)
  const [aviso, setAviso] = useState({ tipo: '', texto: '' })
  const [cobrados, setCobrados] = useState({ fase: 'cargando', datos: null, mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [confirmando, setConfirmando] = useState(null) // id del pago al que se pide confirmar la anulación
  const [anulando, setAnulando] = useState(null)
  const controlBusqueda = useRef(null)

  // Lo cobrado en esta caja: al entrar y cada vez que cambia (nuevo pago, anulación, reintento).
  useEffect(() => {
    const control = new AbortController()
    setCobrados((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarPagosCobrados(control.signal)
      .then((datos) => setCobrados({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setCobrados({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  // Una búsqueda nueva cancela la anterior: una respuesta lenta no pisa a la de la búsqueda actual.
  useEffect(() => () => controlBusqueda.current?.abort(), [])

  // Llegar desde «Cobrar ahora» de Formalizar envío: el envío ya viene en la dirección.
  useEffect(() => {
    if (FORMATO_CODIGO.test(codigoInicial)) cargarEnvio(codigoInicial, { nuevo: true })
  }, [])

  // nuevo: búsqueda nueva (se propone quién paga). ajustarMonto: el monto vuelve a ser el saldo.
  function cargarEnvio(codigoLimpio, { nuevo = false, ajustarMonto = true } = {}) {
    controlBusqueda.current?.abort()
    const control = new AbortController()
    controlBusqueda.current = control
    setBusqueda((anterior) => ({ fase: 'buscando', envio: nuevo ? null : anterior.envio, mensaje: '' }))
    obtenerEnvioParaCobro(codigoLimpio, control.signal)
      .then((envio) => {
        setBusqueda({ fase: 'listo', envio, mensaje: '' })
        setFormulario((anterior) => ({
          ...anterior,
          monto: nuevo || ajustarMonto ? envio.saldo : anterior.monto,
          pagadoPor: nuevo ? envio.pagadoPorSugerido : anterior.pagadoPor,
        }))
      })
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setBusqueda({ fase: fallo.estado === 404 ? 'no_encontrado' : 'error', envio: null, mensaje: fallo.message })
      })
  }

  function buscar(evento) {
    evento.preventDefault()
    const limpio = codigo.trim().toUpperCase()
    setAviso({ tipo: '', texto: '' })
    setErrores({})
    if (!FORMATO_CODIGO.test(limpio)) {
      controlBusqueda.current?.abort()
      setBusqueda({ fase: 'no_encontrado', envio: null, mensaje: 'El código de seguimiento empieza con EN y tiene 12 caracteres (por ejemplo, EN4T7Q0P2LKX).' })
      return
    }
    cargarEnvio(limpio, { nuevo: true })
  }

  function cambiar(campo, valor) {
    setFormulario((anterior) => ({ ...anterior, [campo]: valor }))
    if (errores[campo]) setErrores((anterior) => ({ ...anterior, [campo]: '' }))
  }

  async function registrar(evento) {
    evento.preventDefault()
    const envio = busqueda.envio
    if (!envio || registrando) return

    const monto = formulario.monto.trim()
    const nuevos = {}
    if (!FORMATO_MONTO.test(monto)) nuevos.monto = 'Ingresa el monto en soles, con hasta 2 decimales.'
    else if (enCentimos(monto) <= 0) nuevos.monto = 'El monto debe ser mayor que 0.'
    else if (enCentimos(monto) > enCentimos(envio.saldo)) nuevos.monto = `El monto supera el saldo del envío (S/ ${envio.saldo}).`
    setErrores(nuevos)
    if (nuevos.monto) {
      document.getElementById('monto')?.focus()
      return
    }

    setRegistrando(true)
    setAviso({ tipo: '', texto: '' })
    try {
      const { pago, envio: actualizado } = await registrarPago({ codigoSeguimiento: envio.codigoSeguimiento, monto, pagadoPor: formulario.pagadoPor, metodoPago: formulario.metodoPago })
      setBusqueda({ fase: 'listo', envio: actualizado, mensaje: '' })
      setFormulario((anterior) => ({ ...anterior, monto: actualizado.saldo }))
      setAviso({
        tipo: 'ok',
        texto: `Pago de S/ ${pago.monto} registrado (${NOMBRE_METODO[pago.metodoPago].toLowerCase()}).${actualizado.saldo === '0.00' ? ' El envío quedó pagado por completo.' : ` Saldo: S/ ${actualizado.saldo}.`}`,
      })
      setIntento((n) => n + 1)
    } catch (fallo) {
      if (fallo.estado === 422 && Object.keys(fallo.campos ?? {}).length > 0) {
        setErrores(fallo.campos)
        // El saldo cambió desde que se cargó el envío (otra caja cobró): se vuelve a leer sin tocar lo que se escribió.
        if (fallo.campos.monto) cargarEnvio(envio.codigoSeguimiento, { ajustarMonto: false })
      } else {
        setAviso({ tipo: 'error', texto: fallo.message })
        if (fallo.estado === 404 || fallo.estado === 409) cargarEnvio(envio.codigoSeguimiento) // el estado cambió: se recarga
      }
    } finally {
      setRegistrando(false)
    }
  }

  async function anular(pago) {
    setAnulando(pago.id)
    setAviso({ tipo: '', texto: '' })
    try {
      await anularPago(pago.id)
      setAviso({ tipo: 'ok', texto: `Pago de S/ ${pago.monto} del envío ${pago.codigoSeguimiento} anulado.` })
      if (busqueda.envio?.codigoSeguimiento === pago.codigoSeguimiento) cargarEnvio(pago.codigoSeguimiento) // su saldo cambió
    } catch (fallo) {
      setAviso({ tipo: 'error', texto: fallo.message })
    } finally {
      setAnulando(null)
      setConfirmando(null)
      setIntento((n) => n + 1) // en todos los casos se recarga la lista: refleja lo que realmente quedó
    }
  }

  const envio = busqueda.envio
  const cobrable = envio !== null && envio.estado !== 'ANULADO' && envio.saldo !== '0.00'

  return (
    <section className="caja">
      <h1>Caja{usuario?.sucursal ? ` · ${usuario.sucursal.nombre}` : ''}</h1>

      <div className="panel registrar-pago">
        <h2>Registrar pago</h2>

        <form className="buscar-reserva" onSubmit={buscar} noValidate>
          <div className="campo">
            <label htmlFor="codigoEnvio">Envío</label>
            <input id="codigoEnvio" type="text" autoComplete="off" maxLength={12} placeholder="EN4T7Q0P2LKX" value={codigo} onChange={(e) => setCodigo(e.target.value)} />
          </div>
          {/* No se deshabilita mientras busca: una búsqueda nueva cancela la anterior. */}
          <button type="submit" className="boton boton-azul">
            {busqueda.fase === 'buscando' ? 'Buscando…' : 'Buscar'}
          </button>
        </form>

        {busqueda.fase === 'no_encontrado' && (
          <div className="resultado error" role="alert">
            {busqueda.mensaje}
          </div>
        )}
        {busqueda.fase === 'error' && (
          <div className="resultado error" role="alert">
            <strong>No se pudo cargar el envío.</strong> {busqueda.mensaje}
          </div>
        )}

        {aviso.texto && (
          <div className={`resultado ${aviso.tipo === 'ok' ? 'ok' : 'error'}`} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
            {aviso.texto}
          </div>
        )}

        {envio && (
          <>
            <p className="resumen-cobro" aria-live="polite">
              <span>
                Importe <strong>S/ {envio.importe}</strong>
              </span>
              <span>
                Pagado <strong>S/ {envio.pagado}</strong>
              </span>
              <span>
                Saldo <strong>S/ {envio.saldo}</strong>
              </span>
            </p>
            <p className="ayuda">
              {envio.codigoSeguimiento} · {envio.origen.nombre} → {envio.destino.nombre} · de {envio.remitente} para {envio.destinatario} · {envio.estado}
            </p>
            {envio.estado === 'ANULADO' && <p className="mensaje-aviso">El envío está anulado: no admite pagos.</p>}
            {envio.estado !== 'ANULADO' && envio.saldo === '0.00' && <p className="mensaje-aviso">Este envío ya está pagado por completo.</p>}
          </>
        )}

        {cobrable && (
          <form className="formulario-pago" onSubmit={registrar} noValidate>
            <div className="fila-doble">
              <CampoTexto
                id="monto"
                etiqueta="Monto"
                inputMode="decimal"
                autoComplete="off"
                value={formulario.monto}
                error={errores.monto}
                onChange={(e) => cambiar('monto', e.target.value)}
              />
              <CampoTexto id="pagadoPor" etiqueta="Pagado por" como="select" value={formulario.pagadoPor} error={errores.pagadoPor} onChange={(e) => cambiar('pagadoPor', e.target.value)}>
                {PAGADORES.map((p) => (
                  <option key={p} value={p}>
                    {p}
                  </option>
                ))}
              </CampoTexto>
            </div>

            <fieldset className="campo metodos-pago">
              <legend>Método</legend>
              <div className="filtros">
                {METODOS.map((m) => (
                  <label key={m} className={formulario.metodoPago === m ? 'filtro activo' : 'filtro'}>
                    <input type="radio" name="metodoPago" id={`metodo-${m}`} value={m} className="solo-lectores" checked={formulario.metodoPago === m} onChange={() => cambiar('metodoPago', m)} />
                    {m}
                  </label>
                ))}
              </div>
              {errores.metodoPago && <p className="campo-error">{errores.metodoPago}</p>}
            </fieldset>

            <div className="acciones-formulario">
              <button type="submit" className="boton boton-azul" disabled={registrando}>
                {registrando ? 'Registrando…' : 'Registrar pago'}
              </button>
              <p className="ayuda">Un pago con error se anula, no se borra.</p>
            </div>
          </form>
        )}
      </div>

      <h2>Pagos cobrados aquí</h2>

      {cobrados.fase === 'cargando' && !cobrados.datos && (
        <p role="status" className="cargando">
          Cargando los pagos…
        </p>
      )}
      {cobrados.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar los pagos.</strong> {cobrados.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {cobrados.datos && cobrados.fase !== 'error' && cobrados.datos.pagos.length === 0 && (
        <div className="panel estado-vacio">
          <p>Todavía no se cobró ningún pago en esta caja.</p>
        </div>
      )}
      {cobrados.datos && cobrados.fase !== 'error' && cobrados.datos.pagos.length > 0 && (
        <>
          <div className="tabla-envoltorio">
            <table className="tabla-reservas tabla-pagos">
              <caption className="solo-lectores">Pagos cobrados en esta caja</caption>
              <thead>
                <tr>
                  <th scope="col">Fecha</th>
                  <th scope="col">Envío</th>
                  <th scope="col">Monto</th>
                  <th scope="col">Método</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Acción</th>
                </tr>
              </thead>
              <tbody>
                {cobrados.datos.pagos.map((pago) => (
                  <tr key={pago.id}>
                    <th scope="row">{fechaHoraBreve(pago.fechaHora)}</th>
                    <td data-etiqueta="Envío">{pago.codigoSeguimiento}</td>
                    <td data-etiqueta="Monto">{pago.monto}</td>
                    <td data-etiqueta="Método">{pago.metodoPago}</td>
                    <td data-etiqueta="Estado">
                      <InsigniaEstado estado={pago.estado} />
                    </td>
                    <td className="celda-accion" data-etiqueta="Acción">
                      {pago.estado === 'REGISTRADO' && confirmando !== pago.id && (
                        <button type="button" className="boton boton-claro" onClick={() => setConfirmando(pago.id)}>
                          Anular
                        </button>
                      )}
                      {pago.estado === 'REGISTRADO' && confirmando === pago.id && (
                        <div className="confirmar-cancelacion" role="group" aria-label={`Confirmar la anulación del pago de ${pago.monto} del envío ${pago.codigoSeguimiento}`}>
                          <span>¿Anular S/ {pago.monto}?</span>
                          <button type="button" className="boton boton-marino" onClick={() => anular(pago)} disabled={anulando === pago.id}>
                            {anulando === pago.id ? 'Anulando…' : 'Sí, anular'}
                          </button>
                          <button type="button" className="boton boton-claro" onClick={() => setConfirmando(null)} disabled={anulando === pago.id}>
                            No
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {cobrados.datos.hayMas && <p className="ayuda">Se muestran los {cobrados.datos.pagos.length} pagos más recientes; los totales incluyen todos los de esta caja.</p>}
        </>
      )}

      {cobrados.datos && cobrados.fase !== 'error' && (
        <div className="totales-caja" role="group" aria-label="Totales cobrados (no incluyen los pagos anulados)">
          {METODOS.filter((m) => Number(cobrados.datos.totales[m]) > 0).map((m) => (
            <p key={m} className="total-metodo">
              <strong>S/ {cobrados.datos.totales[m]}</strong>
              <span>{NOMBRE_METODO[m]}</span>
            </p>
          ))}
          <p className="total-general">
            <strong>S/ {cobrados.datos.totales.total}</strong>
            <span>Total</span>
          </p>
        </div>
      )}
    </section>
  )
}
