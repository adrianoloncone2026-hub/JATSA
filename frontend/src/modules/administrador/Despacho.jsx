import { useEffect, useState } from 'react'
import { cargarEnvio, listarEnviosEnEspera, listarSalidas, quitarEnvio } from '../../api/despacho.js'
import { obtenerViaje } from '../../api/viajes.js'
import { useAuth } from '../../auth/AuthProvider.jsx'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import TablaCarga from '../../components/TablaCarga.jsx'
import { kilosSinCeros } from '../../utils/formato.js'
import ProgramarViaje from './ProgramarViaje.jsx'

// Diapositiva 21 del prototipo: viajes que salen de la agencia, programar uno y cargar los envíos que esperan aquí.
export default function Despacho() {
  const { usuario } = useAuth()
  const [salidas, setSalidas] = useState({ fase: 'cargando', datos: null, mensaje: '' }) // cargando | listo | error
  const [espera, setEspera] = useState({ fase: 'cargando', datos: null, mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [aviso, setAviso] = useState({ tipo: '', texto: '' })
  const [programando, setProgramando] = useState(false)
  const [abierto, setAbierto] = useState(null) // código del viaje cuya carga se muestra
  const [detalle, setDetalle] = useState({ fase: 'inactivo', viaje: null, mensaje: '' })
  const [elegidos, setElegidos] = useState({}) // código del envío → viaje elegido (si el administrador cambió la sugerencia)
  const [cargando, setCargando] = useState(null)
  const [confirmando, setConfirmando] = useState(null) // envío del que se pide confirmar «Quitar»
  const [quitando, setQuitando] = useState(null)

  // Las dos listas se recargan juntas: al entrar, al reintentar y después de cada cambio.
  useEffect(() => {
    const control = new AbortController()
    setSalidas((anterior) => ({ ...anterior, fase: 'cargando' }))
    setEspera((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarSalidas(control.signal)
      .then((datos) => setSalidas({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setSalidas({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    listarEnviosEnEspera(control.signal)
      .then((datos) => setEspera({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEspera({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  // La carga del viaje abierto: se vuelve a leer con cada recarga para que refleje lo que realmente quedó.
  useEffect(() => {
    if (!abierto) return undefined
    const control = new AbortController()
    obtenerViaje(abierto, control.signal)
      .then((viaje) => setDetalle({ fase: 'listo', viaje, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setDetalle({ fase: 'error', viaje: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [abierto, intento])

  function abrir(codigo) {
    setConfirmando(null)
    if (abierto === codigo) {
      setAbierto(null)
      return
    }
    setDetalle({ fase: 'cargando', viaje: null, mensaje: '' })
    setAbierto(codigo)
  }

  const programados = (salidas.datos?.viajes ?? []).filter((v) => v.estado === 'PROGRAMADO')
  const elegidoPara = (envio) => {
    const elegido = elegidos[envio.codigoSeguimiento] ?? envio.viajeSugerido ?? ''
    return programados.some((v) => v.codigo === elegido) ? elegido : ''
  }

  async function cargar(envio) {
    const viaje = elegidoPara(envio)
    if (!viaje || cargando) return
    setCargando(envio.codigoSeguimiento)
    setAviso({ tipo: '', texto: '' })
    try {
      const cargado = await cargarEnvio(viaje, envio.codigoSeguimiento)
      setAviso({ tipo: 'ok', texto: `Envío ${envio.codigoSeguimiento} cargado en ${cargado.codigo} (${cargado.pesoCargadoKg} de ${kilosSinCeros(cargado.vehiculo.capacidadKg)} kg).` })
    } catch (fallo) {
      setAviso({ tipo: 'error', texto: fallo.message })
    } finally {
      setCargando(null)
      setIntento((n) => n + 1) // en todos los casos se recarga: refleja lo que realmente quedó
    }
  }

  async function quitar(codigoViaje, codigoSeguimiento) {
    setQuitando(codigoSeguimiento)
    setAviso({ tipo: '', texto: '' })
    try {
      await quitarEnvio(codigoViaje, codigoSeguimiento)
      setAviso({ tipo: 'ok', texto: `Envío ${codigoSeguimiento} quitado de ${codigoViaje}: vuelve a esperar en la agencia.` })
    } catch (fallo) {
      setAviso({ tipo: 'error', texto: fallo.message })
    } finally {
      setQuitando(null)
      setConfirmando(null)
      setIntento((n) => n + 1)
    }
  }

  function viajeProgramado(viaje) {
    setProgramando(false)
    setAviso({ tipo: 'ok', texto: `Viaje ${viaje.codigo} programado hacia ${viaje.destino.nombre}.` })
    setIntento((n) => n + 1)
  }

  const nombreAgencia = salidas.datos?.sucursal.nombre ?? usuario?.sucursal?.nombre

  return (
    <section className="despacho">
      <h1>Despacho{nombreAgencia ? ` · salen de ${nombreAgencia}` : ''}</h1>

      <div className="barra-despacho">
        <button type="button" className="boton boton-marino" aria-expanded={programando} onClick={() => setProgramando((p) => !p)}>
          Programar viaje
        </button>
      </div>

      {programando && <ProgramarViaje alProgramar={viajeProgramado} alCancelar={() => setProgramando(false)} />}

      {aviso.texto && (
        <div className={`resultado ${aviso.tipo === 'ok' ? 'ok' : 'error'}`} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
          {aviso.texto}
        </div>
      )}

      <h2>Viajes</h2>
      {salidas.fase === 'cargando' && !salidas.datos && (
        <p role="status" className="cargando">
          Cargando los viajes…
        </p>
      )}
      {salidas.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar los viajes.</strong> {salidas.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {salidas.datos && salidas.fase !== 'error' && salidas.datos.viajes.length === 0 && (
        <div className="panel estado-vacio">
          <p>Esta agencia todavía no tiene viajes. Programa el primero.</p>
        </div>
      )}
      {salidas.datos && salidas.fase !== 'error' && salidas.datos.viajes.length > 0 && (
        <div className="tabla-envoltorio">
          <table className="tabla-reservas tabla-viajes">
            <caption className="solo-lectores">Viajes que salen de esta agencia</caption>
            <thead>
              <tr>
                <th scope="col">Viaje</th>
                <th scope="col">Estado</th>
                <th scope="col">Destino</th>
                <th scope="col">Vehículo y carga</th>
                <th scope="col">Acción</th>
              </tr>
            </thead>
            <tbody>
              {salidas.datos.viajes.map((v) => (
                <tr key={v.codigo} className={abierto === v.codigo ? 'fila-abierta' : undefined}>
                  <th scope="row">{v.codigo}</th>
                  <td data-etiqueta="Estado">
                    <InsigniaEstado estado={v.estado} />
                  </td>
                  <td data-etiqueta="Destino">{v.destino.nombre}</td>
                  <td data-etiqueta="Vehículo y carga">
                    {v.vehiculo.tipo} {v.vehiculo.placa} · {v.pesoCargadoKg} / {kilosSinCeros(v.vehiculo.capacidadKg)} kg
                  </td>
                  <td className="celda-accion" data-etiqueta="Acción">
                    <button type="button" className={v.estado === 'PROGRAMADO' ? 'boton boton-azul' : 'boton boton-claro'} aria-expanded={abierto === v.codigo} onClick={() => abrir(v.codigo)}>
                      {v.estado === 'PROGRAMADO' ? 'Cargar envíos' : 'Ver carga'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {salidas.datos?.hayMasTerminados && <p className="ayuda">Se muestran los 20 viajes terminados más recientes.</p>}

      {abierto && (
        <section className="panel carga-viaje" aria-label={`Carga de ${abierto}`}>
          <div className="carga-viaje-cabecera">
            <h2>Carga de {abierto}</h2>
            <button type="button" className="boton boton-claro" onClick={() => abrir(abierto)}>
              Cerrar
            </button>
          </div>
          {detalle.fase === 'cargando' && (
            <p role="status" className="cargando">
              Cargando la carga…
            </p>
          )}
          {detalle.fase === 'error' && (
            <div className="resultado error" role="alert">
              <strong>No se pudo cargar el viaje.</strong> {detalle.mensaje}
            </div>
          )}
          {detalle.fase === 'listo' && detalle.viaje && (
            <>
              <p className="ayuda">
                {detalle.viaje.origen.nombre} → {detalle.viaje.destino.nombre} · {detalle.viaje.vehiculo.tipo} {detalle.viaje.vehiculo.placa} · peso cargado {detalle.viaje.pesoCargadoKg} de {kilosSinCeros(detalle.viaje.vehiculo.capacidadKg)} kg
              </p>
              {detalle.viaje.carga.length === 0 ? (
                <p className="estado-vacio-texto">
                  {detalle.viaje.estado === 'PROGRAMADO' ? 'Todavía no tiene envíos. Elígelos en «Envíos en espera en esta agencia».' : 'Este viaje salió sin carga.'}
                </p>
              ) : (
                <TablaCarga
                  carga={detalle.viaje.carga}
                  renderAccion={
                    detalle.viaje.estado === 'PROGRAMADO'
                      ? (c) =>
                          confirmando === c.codigoSeguimiento ? (
                            <div className="confirmar-cancelacion" role="group" aria-label={`Confirmar quitar ${c.codigoSeguimiento}`}>
                              <span>¿Quitar {c.codigoSeguimiento}?</span>
                              <button type="button" className="boton boton-marino" onClick={() => quitar(detalle.viaje.codigo, c.codigoSeguimiento)} disabled={quitando === c.codigoSeguimiento}>
                                {quitando === c.codigoSeguimiento ? 'Quitando…' : 'Sí, quitar'}
                              </button>
                              <button type="button" className="boton boton-claro" onClick={() => setConfirmando(null)} disabled={quitando === c.codigoSeguimiento}>
                                No
                              </button>
                            </div>
                          ) : (
                            <button type="button" className="boton boton-claro" onClick={() => setConfirmando(c.codigoSeguimiento)}>
                              Quitar
                            </button>
                          )
                      : undefined
                  }
                />
              )}
            </>
          )}
        </section>
      )}

      <h2>Envíos en espera en esta agencia</h2>
      {espera.fase === 'cargando' && !espera.datos && (
        <p role="status" className="cargando">
          Cargando los envíos en espera…
        </p>
      )}
      {espera.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar los envíos en espera.</strong> {espera.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {espera.datos && espera.fase !== 'error' && espera.datos.envios.length === 0 && (
        <div className="panel estado-vacio">
          <p>No hay envíos esperando en esta agencia.</p>
        </div>
      )}
      {espera.datos && espera.fase !== 'error' && espera.datos.envios.length > 0 && (
        <>
          {programados.length === 0 && <p className="mensaje-aviso">No hay viajes programados: programa uno para poder cargar estos envíos.</p>}
          <div className="tabla-envoltorio">
            <table className="tabla-reservas tabla-espera">
              <caption className="solo-lectores">Envíos en espera en esta agencia</caption>
              <thead>
                <tr>
                  <th scope="col">Envío</th>
                  <th scope="col">Detalle</th>
                  <th scope="col">Destino final</th>
                  <th scope="col">Viaje</th>
                  <th scope="col">Acción</th>
                </tr>
              </thead>
              <tbody>
                {espera.datos.envios.map((e) => {
                  const elegido = elegidoPara(e)
                  return (
                    <tr key={e.codigoSeguimiento}>
                      <th scope="row">{e.codigoSeguimiento}</th>
                      <td data-etiqueta="Detalle">
                        {e.bultos} {e.bultos === 1 ? 'bulto' : 'bultos'} · {e.pesoKg} kg{e.estado === 'EN_TRANSITO' ? ' · en tránsito' : ''}
                      </td>
                      <td data-etiqueta="Destino final">{e.destino.nombre}</td>
                      <td data-etiqueta="Viaje">
                        <select aria-label={`Viaje para ${e.codigoSeguimiento}`} value={elegido} onChange={(ev) => setElegidos((a) => ({ ...a, [e.codigoSeguimiento]: ev.target.value }))} disabled={programados.length === 0}>
                          <option value="">Elige un viaje…</option>
                          {programados.map((v) => (
                            <option key={v.codigo} value={v.codigo}>
                              {v.codigo} → {v.destino.nombre}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="celda-accion" data-etiqueta="Acción">
                        <button type="button" className="boton boton-azul" disabled={!elegido || cargando !== null} onClick={() => cargar(e)}>
                          {cargando === e.codigoSeguimiento ? 'Cargando…' : elegido ? `Cargar en ${elegido}` : 'Cargar'}
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          {espera.datos.hayMas && <p className="ayuda">Se muestran los 200 envíos más antiguos en espera.</p>}
        </>
      )}
    </section>
  )
}
