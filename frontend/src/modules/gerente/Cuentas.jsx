import { useCallback, useEffect, useState } from 'react'
import { listarSucursales } from '../../api/catalogos.js'
import { cambiarEstadoCuenta, consultarPersona, crearCuenta, editarCuenta, listarCuentas } from '../../api/usuarios.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { sinSeparadores } from '../../utils/validaciones.js'

const ROLES = ['GERENTE', 'ADMINISTRADOR', 'TRANSPORTISTA', 'CLIENTE', 'EMPRESARIO']
const ROLES_PERSONAL = ['ADMINISTRADOR', 'TRANSPORTISTA']
const FILTROS = { q: '', rol: '', estado: '' }
const NUEVA = { tipoDocumento: 'DNI', numeroDocumento: '', rol: 'TRANSPORTISTA', sucursal: '', usuario: '', contrasena: '' }

const documentoListo = (tipo, numero) => (tipo === 'DNI' ? /^\d{8}$/.test(numero) : /^[A-Za-z0-9]{6,12}$/.test(numero))

// Diapositiva 30 del prototipo: cuentas de usuario del gerente. La cuenta de personal se crea para una persona que YA existe y todavía
export default function Cuentas() {
  const [filtros, setFiltros] = useState(FILTROS)
  const [aplicados, setAplicados] = useState(FILTROS)
  const [lista, setLista] = useState({ fase: 'cargando', datos: null, mensaje: '', errores: {} })
  const [intento, setIntento] = useState(0)
  const [sucursales, setSucursales] = useState([])
  const [seleccionada, setSeleccionada] = useState('')
  const [aviso, setAviso] = useState(null)
  const [enviando, setEnviando] = useState(false)
  const [confirmando, setConfirmando] = useState(false)
  const [edicion, setEdicion] = useState(null)
  const [erroresEdicion, setErroresEdicion] = useState({})
  const [nueva, setNueva] = useState(NUEVA)
  const [erroresNueva, setErroresNueva] = useState({})
  const [persona, setPersona] = useState({ fase: 'inactivo', datos: null })

  useEffect(() => {
    const temporizador = setTimeout(() => setAplicados((actual) => (JSON.stringify(actual) === JSON.stringify(filtros) ? actual : filtros)), filtros.q === aplicados.q ? 0 : 350)
    return () => clearTimeout(temporizador)
  }, [filtros, aplicados.q])

  useEffect(() => {
    const control = new AbortController()
    setLista((anterior) => ({ ...anterior, fase: 'cargando', errores: {} }))
    listarCuentas(aplicados, control.signal)
      .then((datos) => setLista({ fase: 'listo', datos, mensaje: '', errores: {} }))
      .catch((fallo) => {
        if (fallo.name !== 'AbortError') setLista({ fase: 'error', datos: null, mensaje: fallo.message, errores: fallo.campos ?? {} })
      })
    return () => control.abort()
  }, [aplicados, intento])

  useEffect(() => {
    const control = new AbortController()
    listarSucursales(control.signal)
      .then((datos) => setSucursales(datos.sucursales.filter((s) => s.activa)))
      .catch(() => {})
    return () => control.abort()
  }, [])

  const numeroLimpio = sinSeparadores(nueva.numeroDocumento)
  useEffect(() => {
    if (!documentoListo(nueva.tipoDocumento, numeroLimpio)) {
      setPersona({ fase: 'inactivo', datos: null })
      return undefined
    }
    const control = new AbortController()
    setPersona({ fase: 'consultando', datos: null })
    consultarPersona(nueva.tipoDocumento, numeroLimpio, control.signal)
      .then((datos) => setPersona({ fase: 'listo', datos }))
      .catch((fallo) => {
        if (fallo.name !== 'AbortError') setPersona({ fase: 'error', datos: null })
      })
    return () => control.abort()
  }, [nueva.tipoDocumento, numeroLimpio])

  const recargar = useCallback(() => setIntento((n) => n + 1), [])
  const cuentas = lista.datos?.cuentas ?? []
  const elegida = cuentas.find((c) => c.usuario === seleccionada) ?? null
  const cambiarFiltro = (campo, valor) => setFiltros((f) => ({ ...f, [campo]: valor }))
  const cambiarNueva = (campo, valor) => setNueva((n) => ({ ...n, [campo]: valor }))

  async function crear(evento) {
    evento.preventDefault()
    setEnviando(true)
    setErroresNueva({})
    setAviso(null)
    try {
      const cuenta = await crearCuenta({ ...nueva, numeroDocumento: numeroLimpio })
      setAviso({ tipo: 'ok', texto: `Cuenta ${cuenta.usuario} creada (${cuenta.rol}, ${cuenta.sucursal?.codigo}). Entrega la contraseña temporal en persona.` })
      setNueva(NUEVA)
      recargar()
    } catch (fallo) {
      if (Object.keys(fallo.campos ?? {}).length > 0) setErroresNueva(fallo.campos)
      else setAviso({ tipo: 'error', texto: fallo.message })
    } finally {
      setEnviando(false)
    }
  }

  async function guardarEdicion(evento) {
    evento.preventDefault()
    setEnviando(true)
    setErroresEdicion({})
    setAviso(null)
    try {
      const cuenta = await editarCuenta(edicion.usuario, { rol: edicion.rol, sucursal: edicion.sucursal })
      setAviso({ tipo: 'ok', texto: `Cuenta ${cuenta.usuario} actualizada: ${cuenta.rol} en ${cuenta.sucursal?.codigo}.` })
      setEdicion(null)
      recargar()
    } catch (fallo) {
      if (Object.keys(fallo.campos ?? {}).length > 0) setErroresEdicion(fallo.campos)
      else setAviso({ tipo: 'error', texto: fallo.message })
    } finally {
      setEnviando(false)
    }
  }

  async function alternar() {
    setConfirmando(false)
    setAviso(null)
    try {
      const cuenta = await cambiarEstadoCuenta(elegida.usuario, !elegida.activa)
      setAviso({ tipo: 'ok', texto: `Cuenta ${cuenta.usuario} ${cuenta.activa ? 'activada' : 'desactivada'}.` })
      recargar()
    } catch (fallo) {
      setAviso({ tipo: 'error', texto: fallo.message })
    }
  }

  return (
    <section className="cuentas">
      <h1>Cuentas de usuario</h1>

      <div className="panel filtros-historial">
        <CampoTexto id="cuenta-q" etiqueta="Buscar usuario o persona" type="search" autoComplete="off" maxLength={50} value={filtros.q} error={lista.errores.q} onChange={(e) => cambiarFiltro('q', e.target.value)} />
        <CampoTexto id="cuenta-rol" etiqueta="Rol" como="select" value={filtros.rol} onChange={(e) => cambiarFiltro('rol', e.target.value)}>
          <option value="">Todos</option>
          {ROLES.map((r) => (
            <option key={r} value={r}>
              {r}
            </option>
          ))}
        </CampoTexto>
        <CampoTexto id="cuenta-estado" etiqueta="Estado" como="select" value={filtros.estado} onChange={(e) => cambiarFiltro('estado', e.target.value)}>
          <option value="">Todos</option>
          <option value="ACTIVA">ACTIVA</option>
          <option value="INACTIVA">INACTIVA</option>
        </CampoTexto>
      </div>

      {aviso && (
        <div className={aviso.tipo === 'ok' ? 'resultado ok' : 'resultado error'} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
          {aviso.texto}
        </div>
      )}

      {lista.fase === 'cargando' && !lista.datos && (
        <p role="status" className="cargando">
          Cargando las cuentas…
        </p>
      )}
      {lista.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar las cuentas.</strong> {Object.keys(lista.errores).length > 0 ? 'Revisa los filtros.' : lista.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={recargar}>
            Reintentar
          </button>
        </div>
      )}
      {lista.fase === 'listo' && cuentas.length === 0 && (
        <div className="panel estado-vacio">
          <p>{JSON.stringify(aplicados) === JSON.stringify(FILTROS) ? 'Todavía no hay cuentas.' : 'No hay cuentas que coincidan con esos filtros.'}</p>
        </div>
      )}
      {lista.datos && cuentas.length > 0 && (
        <>
          <div className="tabla-envoltorio">
            <table className="tabla-reservas tabla-catalogo">
              <caption className="solo-lectores">Cuentas de usuario</caption>
              <thead>
                <tr>
                  <th scope="col">
                    <span className="solo-lectores">Elegir</span>
                  </th>
                  <th scope="col">Usuario</th>
                  <th scope="col">Persona</th>
                  <th scope="col">Rol</th>
                  <th scope="col">Sucursal</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Acción</th>
                </tr>
              </thead>
              <tbody>
                {cuentas.map((c) => (
                  <tr key={c.usuario} className={seleccionada === c.usuario ? 'fila-elegida' : undefined}>
                    <td data-etiqueta="Elegir">
                      <input type="radio" name="cuenta-elegida" aria-label={`Elegir ${c.usuario}`} checked={seleccionada === c.usuario} onChange={() => { setSeleccionada(c.usuario); setConfirmando(false) }} />
                    </td>
                    <th scope="row">
                      {c.usuario}
                      {c.esUsted && <span className="ayuda"> (usted)</span>}
                    </th>
                    <td data-etiqueta="Persona">{c.persona}</td>
                    <td data-etiqueta="Rol">{c.rol}</td>
                    <td data-etiqueta="Sucursal">{c.sucursal ? c.sucursal.codigo : '—'}</td>
                    <td data-etiqueta="Estado">
                      <InsigniaEstado estado={c.activa ? 'ACTIVA' : 'INACTIVA'} />
                    </td>
                    <td data-etiqueta="Acción" className="celda-accion">
                      {c.editable && (
                        <button type="button" className="boton boton-claro" aria-label={`Editar ${c.usuario}`} onClick={() => { setAviso(null); setErroresEdicion({}); setEdicion({ usuario: c.usuario, rol: c.rol, sucursal: c.sucursal?.codigo ?? '' }) }}>
                          Editar
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {lista.datos.hayMas && <p className="ayuda">Se muestran las primeras 200 cuentas: usa los filtros para acotar.</p>}
          <div className="acciones-formulario">
            {confirmando ? (
              <>
                <button type="button" className="boton boton-peligro" onClick={alternar}>
                  Confirmar desactivación de {elegida?.usuario}
                </button>
                <button type="button" className="boton boton-claro" onClick={() => setConfirmando(false)}>
                  No
                </button>
              </>
            ) : (
              <button type="button" className="boton boton-claro" disabled={!elegida} onClick={() => (elegida.activa ? setConfirmando(true) : alternar())}>
                {elegida && !elegida.activa ? 'Activar seleccionada' : 'Desactivar seleccionada'}
              </button>
            )}
            {!elegida && <span className="ayuda">Elige una cuenta de la lista.</span>}
          </div>
        </>
      )}

      {edicion && (
        <form className="panel formulario-catalogo" onSubmit={guardarEdicion} noValidate aria-label="Editar cuenta">
          <h2>Editar {edicion.usuario}</h2>
          <div className="rejilla-formulario">
            <CampoTexto id="edicion-rol" etiqueta="Rol" como="select" value={edicion.rol} error={erroresEdicion.rol} onChange={(e) => setEdicion((x) => ({ ...x, rol: e.target.value }))}>
              {ROLES_PERSONAL.map((r) => (
                <option key={r} value={r}>
                  {r}
                </option>
              ))}
            </CampoTexto>
            <CampoTexto id="edicion-sucursal" etiqueta="Sucursal (obligatoria para este rol)" como="select" value={edicion.sucursal} error={erroresEdicion.sucursal} onChange={(e) => setEdicion((x) => ({ ...x, sucursal: e.target.value }))}>
              <option value="">Elige…</option>
              {sucursales.map((s) => (
                <option key={s.codigo} value={s.codigo}>
                  {s.codigo}
                </option>
              ))}
            </CampoTexto>
          </div>
          <div className="acciones-formulario">
            <button type="submit" className="boton boton-azul" disabled={enviando}>
              {enviando ? 'Guardando…' : 'Guardar cambios'}
            </button>
            <button type="button" className="boton boton-claro" onClick={() => setEdicion(null)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      <form className="panel formulario-catalogo" onSubmit={crear} noValidate aria-label="Nueva cuenta de personal">
        <h2>Nueva cuenta de personal</h2>
        <div className="rejilla-formulario">
          <CampoTexto id="nueva-tipo" etiqueta="Tipo de documento" como="select" value={nueva.tipoDocumento} error={erroresNueva.tipoDocumento} onChange={(e) => cambiarNueva('tipoDocumento', e.target.value)}>
            <option value="DNI">DNI</option>
            <option value="CE">CE</option>
            <option value="PAS">PAS</option>
          </CampoTexto>
          <CampoTexto id="nueva-documento" etiqueta="Documento de la persona" inputMode="numeric" autoComplete="off" value={nueva.numeroDocumento} error={erroresNueva.numeroDocumento} onChange={(e) => cambiarNueva('numeroDocumento', e.target.value)} />
          <CampoTexto id="nueva-rol" etiqueta="Rol" como="select" value={nueva.rol} error={erroresNueva.rol} onChange={(e) => cambiarNueva('rol', e.target.value)}>
            {ROLES_PERSONAL.map((r) => (
              <option key={r} value={r}>
                {r}
              </option>
            ))}
          </CampoTexto>
          <CampoTexto id="nueva-sucursal" etiqueta="Sucursal (obligatoria para este rol)" como="select" value={nueva.sucursal} error={erroresNueva.sucursal} onChange={(e) => cambiarNueva('sucursal', e.target.value)}>
            <option value="">Elige…</option>
            {sucursales.map((s) => (
              <option key={s.codigo} value={s.codigo}>
                {s.codigo}
              </option>
            ))}
          </CampoTexto>
          <CampoTexto id="nueva-usuario" etiqueta="Usuario" autoComplete="off" maxLength={30} value={nueva.usuario} error={erroresNueva.usuario} onChange={(e) => cambiarNueva('usuario', e.target.value)} />
          <CampoTexto id="nueva-contrasena" etiqueta="Contraseña temporal" type="password" autoComplete="new-password" value={nueva.contrasena} error={erroresNueva.contrasena} onChange={(e) => cambiarNueva('contrasena', e.target.value)} />
        </div>
        <p className="ayuda estado-persona" role="status">
          {persona.fase === 'consultando' && 'Buscando a la persona…'}
          {persona.fase === 'error' && 'No se pudo consultar a la persona; el servidor la comprobará al crear la cuenta.'}
          {persona.fase === 'listo' && !persona.datos.existe && 'No hay una persona registrada con ese documento: debe existir antes de crearle una cuenta.'}
          {persona.fase === 'listo' && persona.datos.existe && persona.datos.tieneCuenta && `${persona.datos.nombreCompleto} ya tiene una cuenta.`}
          {persona.fase === 'listo' && persona.datos.existe && !persona.datos.tieneCuenta && `Persona: ${persona.datos.nombreCompleto}`}
        </p>
        <div className="acciones-formulario">
          <button type="submit" className="boton boton-azul" disabled={enviando}>
            {enviando ? 'Creando…' : 'Crear cuenta'}
          </button>
        </div>
      </form>
    </section>
  )
}