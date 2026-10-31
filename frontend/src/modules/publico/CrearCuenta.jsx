import { useRef, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { registrarCuenta } from '../../api/cuenta.js'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { rutaInicioDeRol } from '../../auth/roles.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import {
  errorContrasena,
  errorCorreo,
  errorDocumento,
  errorTelefono,
  errorTelefonoAuxiliar,
  errorUsuario,
  sinSeparadores,
  soloConError,
} from '../../utils/validaciones.js'

const INICIAL = {
  tipoDocumento: 'DNI',
  numeroDocumento: '',
  nombreCompleto: '',
  telefono: '',
  telefonoAuxiliar: '',
  correo: '',
  usuario: '',
  contrasena: '',
  confirmacion: '',
}

// Orden en que aparecen los campos: sirve para enfocar el primero con error.
const ORDEN_CAMPOS = ['tipoDocumento', 'numeroDocumento', 'nombreCompleto', 'telefono', 'telefonoAuxiliar', 'correo', 'usuario', 'contrasena', 'confirmacion']

// Diapositiva 10 del prototipo: con DNI se crea una cuenta CLIENTE; con RUC, una cuenta EMPRESARIO.
export default function CrearCuenta() {
  const { usuario: sesion, cargando } = useAuth()
  const navegar = useNavigate()
  const formulario = useRef(null)

  const [campos, setCampos] = useState(INICIAL)
  const [errores, setErrores] = useState({})
  const [errorGeneral, setErrorGeneral] = useState('')
  const [enviando, setEnviando] = useState(false)

  // Quien ya tiene sesión no necesita crear otra cuenta.
  if (!cargando && sesion) return <Navigate to={rutaInicioDeRol(sesion.rol)} replace />

  const esRuc = campos.tipoDocumento === 'RUC'

  function cambiar(evento) {
    const { name, value } = evento.target
    setCampos((anteriores) => ({ ...anteriores, [name]: value }))
    // El error de un campo desaparece en cuanto se vuelve a editar.
    setErrores((anteriores) => (anteriores[name] ? { ...anteriores, [name]: '' } : anteriores))
  }

  function validar() {
    return soloConError({
      tipoDocumento: ['DNI', 'RUC'].includes(campos.tipoDocumento) ? '' : 'Elige DNI o RUC.',
      numeroDocumento: errorDocumento(campos.tipoDocumento, campos.numeroDocumento),
      nombreCompleto: campos.nombreCompleto.trim() === '' ? `Ingresa ${esRuc ? 'la razón social' : 'tus nombres y apellidos'}.` : '',
      telefono: errorTelefono(campos.telefono),
      telefonoAuxiliar: errorTelefonoAuxiliar(campos.telefonoAuxiliar, campos.telefono),
      correo: errorCorreo(campos.correo),
      usuario: errorUsuario(campos.usuario),
      contrasena: errorContrasena(campos.contrasena, campos.usuario),
      confirmacion: campos.confirmacion === campos.contrasena ? '' : 'Las contraseñas no coinciden.',
    })
  }

  function enfocarPrimerError(errorPorCampo) {
    const primero = ORDEN_CAMPOS.find((campo) => errorPorCampo[campo])
    if (primero) formulario.current?.querySelector(`[name="${primero}"]`)?.focus()
  }

  async function enviar(evento) {
    evento.preventDefault()
    setErrorGeneral('')

    const pendientes = validar()
    setErrores(pendientes)
    if (Object.keys(pendientes).length > 0) {
      enfocarPrimerError(pendientes)
      return
    }

    setEnviando(true)
    try {
      const creada = await registrarCuenta({
        tipoDocumento: campos.tipoDocumento,
        numeroDocumento: sinSeparadores(campos.numeroDocumento),
        nombreCompleto: campos.nombreCompleto,
        telefono: sinSeparadores(campos.telefono),
        telefonoAuxiliar: sinSeparadores(campos.telefonoAuxiliar),
        correo: campos.correo.trim(),
        usuario: campos.usuario.trim(),
        contrasena: campos.contrasena,
      })
      // La cuenta no abre sesión: se pasa a «Ingresar» con un aviso y el usuario ya escrito.
      navegar('/ingresar', { replace: true, state: { cuentaCreada: true, usuario: creada.usuario } })
    } catch (fallo) {
      setEnviando(false)
      const delServidor = fallo.campos ?? {}
      if (Object.keys(delServidor).length > 0) {
        // Errores por campo (datos inválidos, usuario en uso): se muestran junto a su campo.
        setErrores(delServidor)
        enfocarPrimerError(delServidor)
      } else {
        // Sin campo concreto (documento que ya tiene cuenta, sin conexión, error del servidor): arriba.
        setErrorGeneral(fallo.message)
      }
    }
  }

  return (
    <section className="crear-cuenta">
      <h1>Crear cuenta</h1>
      <p className="subtitulo">Con DNI se crea una cuenta CLIENTE; con RUC, una cuenta EMPRESARIO</p>

      {errorGeneral && (
        <div className="resultado error" role="alert">
          {errorGeneral}
        </div>
      )}

      <form ref={formulario} className="panel formulario-cuenta" onSubmit={enviar} noValidate>
        <div className="columna">
          <div className="fila-doble fila-documento">
            <CampoTexto id="tipoDocumento" name="tipoDocumento" etiqueta="Tipo de documento" como="select" value={campos.tipoDocumento} onChange={cambiar} error={errores.tipoDocumento}>
              <option value="DNI">DNI</option>
              <option value="RUC">RUC</option>
            </CampoTexto>
            <CampoTexto id="numeroDocumento" name="numeroDocumento" etiqueta="Número de documento" inputMode="numeric" autoComplete="off" maxLength={14} value={campos.numeroDocumento} onChange={cambiar} error={errores.numeroDocumento} />
          </div>
          <CampoTexto id="nombreCompleto" name="nombreCompleto" etiqueta={esRuc ? 'Razón social' : 'Nombres y apellidos'} autoComplete="name" maxLength={150} value={campos.nombreCompleto} onChange={cambiar} error={errores.nombreCompleto} />
          <div className="fila-doble">
            <CampoTexto id="telefono" name="telefono" etiqueta="Teléfono" type="tel" inputMode="numeric" autoComplete="tel" maxLength={13} value={campos.telefono} onChange={cambiar} error={errores.telefono} />
            <CampoTexto id="telefonoAuxiliar" name="telefonoAuxiliar" etiqueta="Teléfono auxiliar (opcional)" type="tel" inputMode="numeric" maxLength={13} value={campos.telefonoAuxiliar} onChange={cambiar} error={errores.telefonoAuxiliar} />
          </div>
          <CampoTexto id="correo" name="correo" etiqueta="Correo (opcional)" type="email" autoComplete="email" maxLength={120} value={campos.correo} onChange={cambiar} error={errores.correo} />
        </div>

        <div className="columna">
          <CampoTexto id="usuario" name="usuario" etiqueta="Usuario" autoComplete="username" maxLength={30} value={campos.usuario} onChange={cambiar} error={errores.usuario} />
          <CampoTexto id="contrasena" name="contrasena" etiqueta="Contraseña" type="password" autoComplete="new-password" value={campos.contrasena} onChange={cambiar} error={errores.contrasena} />
          <CampoTexto id="confirmacion" name="confirmacion" etiqueta="Confirmar contraseña" type="password" autoComplete="new-password" value={campos.confirmacion} onChange={cambiar} error={errores.confirmacion} />
          <p className="nota-datos">Si ya enviaste o recibiste con este {esRuc ? 'RUC' : 'DNI'}, usamos tus datos guardados</p>
        </div>

        <div className="acciones-formulario">
          <button type="submit" className="boton boton-azul" disabled={enviando}>
            {enviando ? 'Creando cuenta…' : 'Crear cuenta'}
          </button>
          <Link to="/ingresar" className="boton boton-claro">
            Ya tengo cuenta
          </Link>
        </div>
      </form>
    </section>
  )
}
