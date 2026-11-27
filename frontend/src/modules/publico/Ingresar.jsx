import { useState } from 'react'
import Mosaico from '../../components/Mosaico.jsx'
import { Link, Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { rutaInicioDeRol } from '../../auth/roles.js'

function destinoTrasIngresar(rol, desde) {
  const inicio = rutaInicioDeRol(rol)
  return typeof desde === 'string' && desde.startsWith(inicio) ? desde : inicio
}

// Diapositivas 8 y 9 del prototipo: ingreso y acceso con error.
export default function Ingresar() {
  const { usuario, ingresar, cargando } = useAuth()
  const ubicacion = useLocation()

  const cuentaCreada = ubicacion.state?.cuentaCreada === true
  const [campos, setCampos] = useState({
    usuario: cuentaCreada && typeof ubicacion.state?.usuario === 'string' ? ubicacion.state.usuario : '',
    contrasena: '',
    recordarme: false,
  })
  const [verContrasena, setVerContrasena] = useState(false)
  const [enviando, setEnviando] = useState(false)
  const [error, setError] = useState('')

  if (!cargando && usuario) {
    return <Navigate to={destinoTrasIngresar(usuario.rol, ubicacion.state?.desde)} replace />
  }

  function cambiar(evento) {
    const { name, type, value, checked } = evento.target
    setCampos((anteriores) => ({ ...anteriores, [name]: type === 'checkbox' ? checked : value }))
  }

  async function enviar(evento) {
    evento.preventDefault()
    setError('')
    setEnviando(true)
    try {
      await ingresar({
        usuario: campos.usuario.trim(),
        contrasena: campos.contrasena,
        recordarme: campos.recordarme,
      })
    } catch (fallo) {
      setError(fallo.message)
      setCampos((anteriores) => ({ ...anteriores, contrasena: '' }))
      setEnviando(false)
    }
  }

  return (
    <div className="acceso-fila">
    <Mosaico vertical ids={[64, 15, 4, 1]} />
    <section className="formulario-acceso acceso-compacto">
      <h1>Ingresar</h1>
      <p className="subtitulo">Con tu usuario y contraseña entras a tu panel según tu rol.</p>

      {cuentaCreada && !error && (
        <div className="resultado ok" role="status">
          Cuenta creada. Ingresa con tu usuario y tu contraseña.
        </div>
      )}

      {error && (
        <div className="resultado error" role="alert">
          {error}
        </div>
      )}

      <form onSubmit={enviar} noValidate>
        <label htmlFor="usuario">Usuario</label>
        <input
          id="usuario"
          name="usuario"
          type="text"
          autoComplete="username"
          maxLength={30}
          required
          autoFocus
          value={campos.usuario}
          onChange={cambiar}
        />

        <label htmlFor="contrasena">Contraseña</label>
        <div className="campo-con-boton">
          <input
            id="contrasena"
            name="contrasena"
            type={verContrasena ? 'text' : 'password'}
            autoComplete="current-password"
            required
            value={campos.contrasena}
            onChange={cambiar}
          />
          <button type="button" className="secundario" onClick={() => setVerContrasena((v) => !v)}>
            {verContrasena ? 'Ocultar' : 'Mostrar'}
          </button>
        </div>

        <label className="casilla">
          <input name="recordarme" type="checkbox" checked={campos.recordarme} onChange={cambiar} />
          Recordarme en este equipo
        </label>

        <button type="submit" disabled={enviando}>
          {enviando ? 'Ingresando…' : 'Ingresar'}
        </button>
      </form>

      <p className="ayuda acceso-pie">
        ¿Sin cuenta? <Link to="/crear-cuenta">Crear cuenta</Link> · <Link to="/">Volver al inicio</Link>
        <br />
        ¿Olvidaste tu contraseña? Pídela en tu agencia o al gerente.
      </p>
    </section>
    </div>
  )
}
