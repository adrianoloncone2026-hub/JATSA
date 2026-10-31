import { useState } from 'react'
import { Link, Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { rutaInicioDeRol } from '../../auth/roles.js'

// Vuelve a la pantalla que el usuario quería abrir solo si pertenece al área de su rol;
// si no, va al inicio de su área.
function destinoTrasIngresar(rol, desde) {
  const inicio = rutaInicioDeRol(rol)
  return typeof desde === 'string' && desde.startsWith(inicio) ? desde : inicio
}

// Diapositivas 8 y 9 del prototipo: ingreso y acceso con error.
export default function Ingresar() {
  const { usuario, ingresar, cargando } = useAuth()
  const ubicacion = useLocation()

  // Al venir de «Crear cuenta» se avisa y se deja escrito el usuario recién creado.
  const cuentaCreada = ubicacion.state?.cuentaCreada === true
  const [campos, setCampos] = useState({
    usuario: cuentaCreada && typeof ubicacion.state?.usuario === 'string' ? ubicacion.state.usuario : '',
    contrasena: '',
    recordarme: false,
  })
  const [verContrasena, setVerContrasena] = useState(false)
  const [enviando, setEnviando] = useState(false)
  const [error, setError] = useState('')

  // Quien tiene sesión (ya la tenía o acaba de ingresar) no necesita ver el formulario.
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
      // Al guardarse el usuario, el bloque de arriba redirige a su destino.
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
    <section className="formulario-acceso">
      <p>
        <Link to="/">← Volver al inicio</Link>
      </p>
      <h1>Ingresar</h1>
      <p>Usa el usuario y la contraseña de tu cuenta. El sistema te lleva a tu panel según tu rol.</p>

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

      <p className="ayuda">¿Olvidaste tu contraseña? Pídela en tu agencia o al gerente.</p>
      <p className="ayuda">
        ¿Eres cliente y no tienes cuenta? <Link to="/crear-cuenta">Crear cuenta</Link>
      </p>
    </section>
  )
}
