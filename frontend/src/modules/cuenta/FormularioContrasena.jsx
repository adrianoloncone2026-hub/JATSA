import { useRef, useState } from 'react'
import { cambiarContrasena } from '../../api/cuenta.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import { errorContrasena, soloConError } from '../../utils/validaciones.js'

// Formulario para cambiar la PROPIA contraseña (contraseña actual, nueva y confirmación). Lo usan «Mi cuenta» (cliente y empresario)
export default function FormularioContrasena({ usuario, alCancelar, alGuardar }) {
  const [campos, setCampos] = useState({ contrasenaActual: '', contrasenaNueva: '', confirmacion: '' })
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
    const primero = ['contrasenaActual', 'contrasenaNueva', 'confirmacion'].find((campo) => errorPorCampo[campo])
    if (primero) formulario.current?.querySelector(`[name="${primero}"]`)?.focus()
  }

  async function guardar(evento) {
    evento.preventDefault()
    setErrorGeneral('')
    const pendientes = soloConError({
      contrasenaActual: campos.contrasenaActual === '' ? 'Ingresa tu contraseña actual.' : '',
      contrasenaNueva:
        errorContrasena(campos.contrasenaNueva, usuario) ||
        (campos.contrasenaNueva === campos.contrasenaActual ? 'La contraseña nueva debe ser distinta de la actual.' : ''),
      confirmacion: campos.confirmacion === campos.contrasenaNueva ? '' : 'Las contraseñas no coinciden.',
    })
    setErrores(pendientes)
    if (Object.keys(pendientes).length > 0) {
      enfocarPrimero(pendientes)
      return
    }

    setGuardando(true)
    try {
      await cambiarContrasena({ contrasenaActual: campos.contrasenaActual, contrasenaNueva: campos.contrasenaNueva })
      alGuardar()
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
    <form ref={formulario} className="mis-datos" onSubmit={guardar} noValidate aria-label="Cambiar contraseña">
      <h2>Cambiar contraseña</h2>
      {errorGeneral && (
        <div className="resultado error" role="alert">
          {errorGeneral}
        </div>
      )}
      <div className="datos-contacto">
        <CampoTexto id="contrasena-actual" name="contrasenaActual" etiqueta="Contraseña actual" type="password" autoComplete="current-password" value={campos.contrasenaActual} onChange={cambiar} error={errores.contrasenaActual} />
        <CampoTexto id="contrasena-nueva" name="contrasenaNueva" etiqueta="Contraseña nueva" type="password" autoComplete="new-password" value={campos.contrasenaNueva} onChange={cambiar} error={errores.contrasenaNueva} />
        <CampoTexto id="contrasena-confirmacion" name="confirmacion" etiqueta="Confirmar contraseña nueva" type="password" autoComplete="new-password" value={campos.confirmacion} onChange={cambiar} error={errores.confirmacion} />
      </div>
      <div className="acciones-formulario">
        <button type="submit" className="boton boton-azul" disabled={guardando}>
          {guardando ? 'Guardando…' : 'Guardar contraseña'}
        </button>
        <button type="button" className="boton boton-claro" onClick={alCancelar} disabled={guardando}>
          Cancelar
        </button>
      </div>
    </form>
  )
}
