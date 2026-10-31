import { useState } from 'react'
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthProvider.jsx'
import { rutaInicioDeRol } from '../auth/roles.js'
import { enlacesMenu, seccionPorId } from '../routes/definicion.js'

// Marco común de todos los layouts: cabecera con el nombre del área, menú, usuario y contenido.
export default function LayoutBase({ idSeccion }) {
  const seccion = seccionPorId(idSeccion)
  const { usuario, salir } = useAuth()
  const navegar = useNavigate()
  const [saliendo, setSaliendo] = useState(false)
  const [errorSalida, setErrorSalida] = useState('')

  // Con sesión abierta no tiene sentido ofrecer "Ingresar" ni "Crear cuenta".
  const ocultos = usuario ? ['/ingresar', '/crear-cuenta'] : []
  const enlaces = enlacesMenu(idSeccion).filter((enlace) => !ocultos.includes(enlace.to))

  async function cerrarSesion() {
    setErrorSalida('')
    setSaliendo(true)
    try {
      await salir()
      navegar('/', { replace: true })
    } catch (error) {
      // El servidor no confirmó el cierre: la sesión sigue abierta y así se le dice al usuario.
      setErrorSalida(`No se pudo cerrar la sesión. ${error.message} Sigues con la sesión abierta.`)
    } finally {
      setSaliendo(false)
    }
  }

  return (
    <div className={`layout layout-${idSeccion}`}>
      <header className="cabecera">
        <div className="cabecera-fila">
          <span className="marca">JATSA</span>
          <span className="area">{seccion.etiqueta}</span>
          {usuario && (
            <div className="usuario-sesion">
              <span>
                {usuario.nombreCompleto} · <strong>{usuario.rol}</strong>
                {usuario.sucursal && ` · ${usuario.sucursal.codigo}`}
              </span>
              {idSeccion === 'publico' && <Link to={rutaInicioDeRol(usuario.rol)}>Mi panel</Link>}
              <button type="button" className="secundario" onClick={cerrarSesion} disabled={saliendo}>
                Salir
              </button>
            </div>
          )}
        </div>
        {errorSalida && (
          <div className="resultado error" role="alert">
            {errorSalida}
          </div>
        )}
        <nav className="menu" aria-label={`Menú de ${seccion.etiqueta}`}>
          {enlaces.map((enlace) => (
            <NavLink key={enlace.to} to={enlace.to} end>
              {enlace.texto}
            </NavLink>
          ))}
        </nav>
      </header>
      <main className="contenido">
        <Outlet />
      </main>
    </div>
  )
}
