import { useCallback, useEffect, useState } from 'react'
import { crearRuta, listarRutas, listarSucursales, nuevoPrecio } from '../../api/catalogos.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { fechaCorta } from '../../utils/formato.js'

const TIPOS = ['SOBRE', 'PAQUETE', 'CARGA']
const NUEVA = { origen: '', destino: '', tipoEnvio: 'SOBRE', precioBase: '', precioPorKm: '', distanciaKm: '', tiempoHoras: '' }

// Pestaña «Rutas y precios» de Catálogos (diapositiva 29). «Nuevo precio: se cierra la vigencia de la fila actual y se crea otra.
// Los bultos ya cobrados conservan su tarifa.» Las filas cerradas se conservan como historial.
export default function CatalogoRutas() {
  const [estado, setEstado] = useState({ fase: 'cargando', rutas: [], mensaje: '' })
  const [sucursales, setSucursales] = useState([])
  const [intento, setIntento] = useState(0)
  const [historial, setHistorial] = useState(false)
  const [nueva, setNueva] = useState(null) // null = cerrado
  const [cambio, setCambio] = useState(null) // { ruta, campos } del «Nuevo precio»
  const [errores, setErrores] = useState({})
  const [enviando, setEnviando] = useState(false)
  const [aviso, setAviso] = useState(null)

  const recargar = useCallback(() => setIntento((n) => n + 1), [])
  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarRutas(control.signal)
      .then((datos) => setEstado({ fase: 'listo', rutas: datos.rutas, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name !== 'AbortError') setEstado({ fase: 'error', rutas: [], mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  // Las agencias activas llenan «Nueva ruta»; si falla, el formulario queda sin opciones pero la tabla sigue funcionando.
  useEffect(() => {
    const control = new AbortController()
    listarSucursales(control.signal)
      .then((datos) => setSucursales(datos.sucursales.filter((s) => s.activa)))
      .catch(() => {})
    return () => control.abort()
  }, [])

  const visibles = estado.rutas.filter((r) => historial || r.vigente)

  async function guardarNueva(evento) {
    evento.preventDefault()
    setEnviando(true)
    setErrores({})
    try {
      const ruta = await crearRuta({ ...nueva, tiempoHoras: nueva.tiempoHoras === '' ? '' : Number(nueva.tiempoHoras) })
      setAviso({ tipo: 'ok', texto: `Ruta ${ruta.origen.codigo} → ${ruta.destino.codigo} (${ruta.tipoEnvio}) creada, vigente desde hoy.` })
      setNueva(null)
      recargar()
    } catch (fallo) {
      if (Object.keys(fallo.campos ?? {}).length > 0) setErrores(fallo.campos)
      else setAviso({ tipo: 'error', texto: fallo.message })
    } finally {
      setEnviando(false)
    }
  }

  async function guardarPrecio(evento) {
    evento.preventDefault()
    const { ruta, campos } = cambio
    setEnviando(true)
    setErrores({})
    try {
      const creada = await nuevoPrecio(ruta.id, { ...campos, tiempoHoras: campos.tiempoHoras === '' ? '' : Number(campos.tiempoHoras) })
      setAviso({ tipo: 'ok', texto: `Nuevo precio de ${creada.origen.codigo} → ${creada.destino.codigo} (${creada.tipoEnvio}): S/ ${creada.costoBulto} por bulto, vigente desde hoy. La tarifa anterior quedó cerrada.` })
      setCambio(null)
      recargar()
    } catch (fallo) {
      if (Object.keys(fallo.campos ?? {}).length > 0) setErrores(fallo.campos)
      else setAviso({ tipo: 'error', texto: fallo.message })
    } finally {
      setEnviando(false)
    }
  }

  const campoNueva = (campo, valor) => setNueva((n) => ({ ...n, [campo]: valor }))
  const campoCambio = (campo, valor) => setCambio((c) => ({ ...c, campos: { ...c.campos, [campo]: valor } }))

  return (
    <section aria-label="Rutas y precios">
      <div className="barra-historial">
        <h2>Rutas y precios</h2>
        <div className="acciones-formulario">
          <label className="casilla">
            <input type="checkbox" checked={historial} onChange={(e) => setHistorial(e.target.checked)} /> Ver tarifas cerradas
          </label>
          <button
            type="button"
            className="boton boton-azul"
            onClick={() => {
              setAviso(null)
              setErrores({})
              setCambio(null)
              setNueva(NUEVA)
            }}
          >
            Nueva ruta
          </button>
        </div>
      </div>
      {aviso && (
        <div className={aviso.tipo === 'ok' ? 'resultado ok' : 'resultado error'} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
          {aviso.texto}
        </div>
      )}

      {nueva && (
        <form className="panel formulario-catalogo" onSubmit={guardarNueva} noValidate aria-label="Nueva ruta">
          <h3>Nueva ruta</h3>
          <div className="rejilla-formulario">
            <CampoTexto id="ruta-origen" etiqueta="Origen" como="select" value={nueva.origen} error={errores.origen} onChange={(e) => campoNueva('origen', e.target.value)}>
              <option value="">Elige…</option>
              {sucursales.map((s) => (
                <option key={s.codigo} value={s.codigo}>
                  {s.nombre}
                </option>
              ))}
            </CampoTexto>
            <CampoTexto id="ruta-destino" etiqueta="Destino" como="select" value={nueva.destino} error={errores.destino} onChange={(e) => campoNueva('destino', e.target.value)}>
              <option value="">Elige…</option>
              {sucursales.map((s) => (
                <option key={s.codigo} value={s.codigo}>
                  {s.nombre}
                </option>
              ))}
            </CampoTexto>
            <CampoTexto id="ruta-tipo" etiqueta="Tipo" como="select" value={nueva.tipoEnvio} error={errores.tipoEnvio} onChange={(e) => campoNueva('tipoEnvio', e.target.value)}>
              {TIPOS.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </CampoTexto>
            <CampoTexto id="ruta-base" etiqueta="Base S/" inputMode="decimal" value={nueva.precioBase} error={errores.precioBase} onChange={(e) => campoNueva('precioBase', e.target.value)} />
            <CampoTexto id="ruta-km-precio" etiqueta="S/ por km" inputMode="decimal" value={nueva.precioPorKm} error={errores.precioPorKm} onChange={(e) => campoNueva('precioPorKm', e.target.value)} />
            <CampoTexto id="ruta-km" etiqueta="Km" inputMode="decimal" value={nueva.distanciaKm} error={errores.distanciaKm} onChange={(e) => campoNueva('distanciaKm', e.target.value)} />
            <CampoTexto id="ruta-horas" etiqueta="Horas" inputMode="numeric" value={nueva.tiempoHoras} error={errores.tiempoHoras} onChange={(e) => campoNueva('tiempoHoras', e.target.value)} />
          </div>
          <div className="acciones-formulario">
            <button type="submit" className="boton boton-azul" disabled={enviando}>
              {enviando ? 'Guardando…' : 'Crear ruta'}
            </button>
            <button type="button" className="boton boton-claro" onClick={() => setNueva(null)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {cambio && (
        <form className="panel formulario-catalogo" onSubmit={guardarPrecio} noValidate aria-label="Nuevo precio">
          <h3>
            Nuevo precio · {cambio.ruta.origen.codigo} → {cambio.ruta.destino.codigo} · {cambio.ruta.tipoEnvio}
          </h3>
          <p className="ayuda">Se cierra la vigencia de la fila actual y se crea otra desde hoy. Los bultos ya cobrados conservan su tarifa.</p>
          <div className="rejilla-formulario">
            <CampoTexto id="precio-base" etiqueta="Base S/" inputMode="decimal" value={cambio.campos.precioBase} error={errores.precioBase} onChange={(e) => campoCambio('precioBase', e.target.value)} />
            <CampoTexto id="precio-km-precio" etiqueta="S/ por km" inputMode="decimal" value={cambio.campos.precioPorKm} error={errores.precioPorKm} onChange={(e) => campoCambio('precioPorKm', e.target.value)} />
            <CampoTexto id="precio-km" etiqueta="Km" inputMode="decimal" value={cambio.campos.distanciaKm} error={errores.distanciaKm} onChange={(e) => campoCambio('distanciaKm', e.target.value)} />
            <CampoTexto id="precio-horas" etiqueta="Horas" inputMode="numeric" value={cambio.campos.tiempoHoras} error={errores.tiempoHoras} onChange={(e) => campoCambio('tiempoHoras', e.target.value)} />
          </div>
          <div className="acciones-formulario">
            <button type="submit" className="boton boton-azul" disabled={enviando}>
              {enviando ? 'Guardando…' : 'Guardar nuevo precio'}
            </button>
            <button type="button" className="boton boton-claro" onClick={() => setCambio(null)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {estado.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando las rutas…
        </p>
      )}
      {estado.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar las rutas.</strong> {estado.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={recargar}>
            Reintentar
          </button>
        </div>
      )}
      {estado.fase === 'listo' && visibles.length === 0 && (
        <div className="panel estado-vacio">
          <p>Todavía no hay tarifas vigentes. Crea la primera ruta.</p>
        </div>
      )}
      {estado.fase === 'listo' && visibles.length > 0 && (
        <div className="tabla-envoltorio">
          <table className="tabla-reservas tabla-catalogo">
            <caption className="solo-lectores">Rutas con sus precios y vigencia</caption>
            <thead>
              <tr>
                <th scope="col">Ruta</th>
                <th scope="col">Tipo</th>
                <th scope="col">Base S/</th>
                <th scope="col">S/ por km</th>
                <th scope="col">Km</th>
                <th scope="col">Horas</th>
                <th scope="col">Desde</th>
                <th scope="col">Hasta</th>
                <th scope="col">Costo bulto</th>
                <th scope="col">Acción</th>
              </tr>
            </thead>
            <tbody>
              {visibles.map((r) => (
                <tr key={r.id} className={r.vigente ? undefined : 'fila-cerrada'}>
                  <th scope="row">
                    {r.origen.codigo} → {r.destino.codigo}
                  </th>
                  <td data-etiqueta="Tipo">{r.tipoEnvio}</td>
                  <td data-etiqueta="Base S/">{r.precioBase}</td>
                  <td data-etiqueta="S/ por km">{r.precioPorKm}</td>
                  <td data-etiqueta="Km">{r.distanciaKm}</td>
                  <td data-etiqueta="Horas">{r.tiempoHoras}</td>
                  <td data-etiqueta="Desde">{fechaCorta(r.vigenteDesde)}</td>
                  <td data-etiqueta="Hasta">{r.vigente ? <InsigniaEstado estado="VIGENTE" /> : fechaCorta(r.vigenteHasta)}</td>
                  <td data-etiqueta="Costo bulto">S/ {r.costoBulto}</td>
                  <td data-etiqueta="Acción" className="celda-accion">
                    {r.vigente && (
                      <button
                        type="button"
                        className="boton boton-claro"
                        aria-label={`Nuevo precio ${r.origen.codigo} a ${r.destino.codigo} ${r.tipoEnvio}`}
                        onClick={() => {
                          setAviso(null)
                          setErrores({})
                          setNueva(null)
                          setCambio({ ruta: r, campos: { precioBase: r.precioBase, precioPorKm: r.precioPorKm, distanciaKm: r.distanciaKm, tiempoHoras: String(r.tiempoHoras) } })
                        }}
                      >
                        Nuevo precio
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="ayuda">Nuevo precio: se cierra la vigencia de la fila actual y se crea otra. Los bultos ya cobrados conservan su tarifa.</p>
    </section>
  )
}