import { useState } from 'react'
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'
import logo from '../assets/logo-jatsa.png'
import { useAuth } from '../auth/AuthProvider.jsx'
import { rutaInicioDeRol } from '../auth/roles.js'
import { enlacesMenu, seccionPorId } from '../routes/definicion.js'

const RUTAS_DE_ACCESO = ['/ingresar', '/crear-cuenta']

// Marco común de todos los layouts: cabecera con logo, menú y acciones, y el contenido de la pantalla.
// En el área pública, «Crear cuenta» e «Ingresar» van como botones a la derecha (como en el prototipo);
// con sesión abierta se muestran el usuario y «Salir».
export default function LayoutBase({ idSeccion }) {
  const seccion = seccionPorId(idSeccion)
  const { usuario, salir } = useAuth()
  const navegar = useNavigate()
  const [saliendo, setSaliendo] = useState(false)
  const [errorSalida, setErrorSalida] = useState('')

  const todos = enlacesMenu(idSeccion)
  const enlaces = idSeccion === 'publico' ? todos.filter((e) => !RUTAS_DE_ACCESO.includes(e.to)) : todos
  const accesos = idSeccion === 'publico' && !usuario ? todos.filter((e) => RUTAS_DE_ACCESO.includes(e.to)) : []

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
          <Link to="/" className="logo" aria-label="JATSA Cargo, ir al inicio">
            <img src={logo} alt="" width={56} height={56} />
          </Link>
          {idSeccion !== 'publico' && <span className="area">{seccion.etiqueta}</span>}
          <nav className="menu" aria-label={`Menú de ${seccion.etiqueta}`}>
            {enlaces.map((enlace) => (
              <NavLink key={enlace.to} to={enlace.to} end>
                {enlace.texto}
              </NavLink>
            ))}
          </nav>
          <div className="acciones-cabecera">
            {accesos.map((enlace) => (
              <Link
                key={enlace.to}
                to={enlace.to}
                className={enlace.to === '/ingresar' ? 'boton boton-azul' : 'boton boton-claro'}
              >
                {enlace.texto}
              </Link>
            ))}
            {usuario && (
              <div className="usuario-sesion">
                <span>
                  {usuario.nombreCompleto} · <strong>{usuario.rol}</strong>
                  {usuario.sucursal && ` · ${usuario.sucursal.codigo}`}
                </span>
                {idSeccion === 'publico' && (
                  <Link to={rutaInicioDeRol(usuario.rol)} className="boton boton-claro">
                    Mi panel
                  </Link>
                )}
                <button type="button" className="boton boton-claro" onClick={cerrarSesion} disabled={saliendo}>
                  Salir
                </button>
              </div>
            )}
          </div>
        </div>
        {errorSalida && (
          <div className="resultado error aviso-cabecera" role="alert">
            {errorSalida}
          </div>
        )}
      </header>
      <main className="contenido">
        <Outlet />
      </main>
    </div>
  )
}
