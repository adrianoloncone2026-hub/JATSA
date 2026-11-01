import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { actualizarContacto, obtenerCuenta } from '../../api/cuenta.js'
import { useCerrarSesion } from '../../auth/useCerrarSesion.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import FormularioContrasena from './FormularioContrasena.jsx'
import { fechaCorta, iniciales } from '../../utils/formato.js'
import { errorCorreo, errorTelefono, errorTelefonoAuxiliar, sinSeparadores, soloConError } from '../../utils/validaciones.js'

const SIN_DATO = '—'

// Diapositiva 17 del prototipo: datos de la cuenta, edición de teléfonos y correo, y cambio de contraseña.
// «Actividad reciente» llegará con los módulos de reservas e historial (4 y 9), que aportan esos datos.
export default function MiCuenta() {
  const [estado, setEstado] = useState({ fase: 'cargando', cuenta: null, mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [panel, setPanel] = useState(null) // null | 'datos' | 'contrasena'
  const [aviso, setAviso] = useState('')
  const { cerrarSesion, saliendo, errorSalida } = useCerrarSesion()

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    obtenerCuenta(control.signal)
      .then((cuenta) => setEstado({ fase: 'listo', cuenta, mensaje: '' }))
      .catch((error) => {
        if (error.name === 'AbortError') return
        setEstado({ fase: 'error', cuenta: null, mensaje: error.message })
      })
    return () => control.abort()
  }, [intento])

  const abrirPanel = useCallback((nombre) => {
    setAviso('')
    setPanel((actual) => (actual === nombre ? null : nombre))
  }, [])

  if (estado.fase === 'cargando') {
    return (
      <p role="status" className="cargando">
        Cargando tu cuenta…
      </p>
    )
  }

  if (estado.fase === 'error') {
    return (
      <div className="resultado error" role="alert">
        <strong>No se pudo cargar tu cuenta.</strong> {estado.mensaje}{' '}
        <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
          Reintentar
        </button>
      </div>
    )
  }

  const { persona, usuario } = estado.cuenta

  return (
    <div className="mi-cuenta">
      <h1 className="solo-lectores">Mi cuenta</h1>

      <div className="mi-cuenta-principal panel">
        <section className="perfil" aria-label="Resumen de la cuenta">
          <span className="avatar avatar-grande" aria-hidden="true">
            {iniciales(persona.nombreCompleto)}
          </span>
          <div className="perfil-tarjeta">
            <h2>{persona.nombreCompleto}</h2>
            <p>
              {persona.tipoDocumento} {persona.numeroDocumento} · usuario {usuario.nombreUsuario} · cuenta {usuario.rol} activa desde{' '}
              {fechaCorta(usuario.fechaCreacion)}
            </p>
            <span className="insignia">{usuario.activo ? 'ACTIVA' : 'DESACTIVADA'}</span>
          </div>
        </section>

        {aviso && (
          <div className="resultado ok" role="status">
            {aviso}
          </div>
        )}

        {panel === 'datos' ? (
          <FormularioDatos
            persona={persona}
            alCancelar={() => setPanel(null)}
            alGuardar={(cuenta) => {
              setEstado({ fase: 'listo', cuenta, mensaje: '' })
              setPanel(null)
              setAviso('Datos actualizados.')
            }}
          />
        ) : (
          <section className="mis-datos" aria-label="Mis datos">
            <h2>Mis datos</h2>
            <dl className="datos-contacto">
              <div>
                <dt>Teléfono</dt>
                <dd>{persona.telefono}</dd>
              </div>
              <div>
                <dt>Teléfono auxiliar</dt>
                <dd>{persona.telefonoAuxiliar ?? SIN_DATO}</dd>
              </div>
              <div>
                <dt>Correo</dt>
                <dd>{persona.correo ?? SIN_DATO}</dd>
              </div>
            </dl>
          </section>
        )}

        {panel === 'contrasena' && (
          <FormularioContrasena
            usuario={usuario.nombreUsuario}
            alCancelar={() => setPanel(null)}
            alGuardar={() => {
              setPanel(null)
              setAviso('Contraseña actualizada. Las demás sesiones abiertas de esta cuenta se cerraron.')
            }}
          />
        )}
      </div>

      <aside className="mi-cuenta-acciones" aria-label="Acciones de la cuenta">
        <button type="button" className="boton boton-azul" aria-expanded={panel === 'datos'} onClick={() => abrirPanel('datos')}>
          Editar datos
        </button>
        <button type="button" className="boton boton-claro" aria-expanded={panel === 'contrasena'} onClick={() => abrirPanel('contrasena')}>
          Cambiar contraseña
        </button>
        <Link to="/cliente/historial" className="boton boton-claro">
          Historial de envíos
        </Link>
        <Link to="/cliente/reservas" className="boton boton-claro">
          Mis reservas
        </Link>
        <Link to="/cliente/pagos" className="boton boton-claro">
          Mis pagos
        </Link>
        <button type="button" className="boton boton-marino" onClick={cerrarSesion} disabled={saliendo}>
          Cerrar sesión
        </button>
        {errorSalida && (
          <p className="mensaje-error" role="alert">
            {errorSalida}
          </p>
        )}
      </aside>
    </div>
  )
}

function FormularioDatos({ persona, alCancelar, alGuardar }) {
  const [campos, setCampos] = useState({
    telefono: persona.telefono,
    telefonoAuxiliar: persona.telefonoAuxiliar ?? '',
    correo: persona.correo ?? '',
  })
  const [errores, setErrores] = useState({})
  const [errorGeneral, setErrorGeneral] = useState('')
  const [guardando, setGuardando] = useState(false)
  const formulario = useRef(null)

  function cambiar(evento) {
    const { name, value } = evento.target
    setCampos((anteriores) => ({ ...anteriores, [name]: value }))
    setErrores((anteriores) => (anteriores[name] ? { ...anteriores, [name]: '' } : anteriores))
  }

  function enfocarPrimero(errorPorCampo) {
    const primero = ['telefono', 'telefonoAuxiliar', 'correo'].find((campo) => errorPorCampo[campo])
    if (primero) formulario.current?.querySelector(`[name="${primero}"]`)?.focus()
  }

  async function guardar(evento) {
    evento.preventDefault()
    setErrorGeneral('')
    const pendientes = soloConError({
      telefono: errorTelefono(campos.telefono),
      telefonoAuxiliar: errorTelefonoAuxiliar(campos.telefonoAuxiliar, campos.telefono),
      correo: errorCorreo(campos.correo),
    })
    setErrores(pendientes)
    if (Object.keys(pendientes).length > 0) {
      enfocarPrimero(pendientes)
      return
    }

    setGuardando(true)
    try {
      alGuardar(
        await actualizarContacto({
          telefono: sinSeparadores(campos.telefono),
          telefonoAuxiliar: sinSeparadores(campos.telefonoAuxiliar),
          correo: campos.correo.trim(),
        }),
      )
    } catch (fallo) {
      setGuardando(false)
      const delServidor = fallo.campos ?? {}
      if (Object.keys(delServidor).length > 0) {
        setErrores(delServidor)
        enfocarPrimero(delServidor)
      } else {
        setErrorGeneral(fallo.message)
      }
    }
  }

  return (
    <form ref={formulario} className="mis-datos" onSubmit={guardar} noValidate aria-label="Editar mis datos">
      <h2>Mis datos</h2>
      {errorGeneral && (
        <div className="resultado error" role="alert">
          {errorGeneral}
        </div>
      )}
      <div className="datos-contacto">
        <CampoTexto id="editar-telefono" name="telefono" etiqueta="Teléfono" type="tel" inputMode="numeric" maxLength={13} value={campos.telefono} onChange={cambiar} error={errores.telefono} />
        <CampoTexto id="editar-auxiliar" name="telefonoAuxiliar" etiqueta="Teléfono auxiliar" type="tel" inputMode="numeric" maxLength={13} value={campos.telefonoAuxiliar} onChange={cambiar} error={errores.telefonoAuxiliar} />
        <CampoTexto id="editar-correo" name="correo" etiqueta="Correo" type="email" maxLength={120} value={campos.correo} onChange={cambiar} error={errores.correo} />
      </div>
      <div className="acciones-formulario">
        <button type="submit" className="boton boton-azul" disabled={guardando}>
          {guardando ? 'Guardando…' : 'Guardar'}
        </button>
        <button type="button" className="boton boton-claro" onClick={alCancelar} disabled={guardando}>
          Cancelar
        </button>
      </div>
    </form>
  )
}
