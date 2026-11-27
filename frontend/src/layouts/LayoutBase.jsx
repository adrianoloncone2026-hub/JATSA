import { Link, NavLink, Outlet } from 'react-router-dom'
import BotonTema from '../components/BotonTema.jsx'
import logo from '../assets/logo-jatsa.png'
import { useAuth } from '../auth/AuthProvider.jsx'
import { ROLES, rutaInicioDeRol } from '../auth/roles.js'
import { useCerrarSesion } from '../auth/useCerrarSesion.js'
import { enlacesMenu, seccionPorId } from '../routes/definicion.js'
import { iniciales } from '../utils/formato.js'

const RUTAS_DE_ACCESO = ['/ingresar', '/crear-cuenta']

// Marco común de todos los layouts: cabecera con logo, menú y acciones, y el contenido de la pantalla.
export default function LayoutBase({ idSeccion }) {
  const seccion = seccionPorId(idSeccion)
  const { usuario } = useAuth()
  const { cerrarSesion, saliendo, errorSalida } = useCerrarSesion()

  const esPersonal = usuario && ![ROLES.CLIENTE, ROLES.EMPRESARIO].includes(usuario.rol)
  const todos = [...enlacesMenu(idSeccion), ...(idSeccion === 'cliente' && esPersonal ? [{ to: rutaInicioDeRol(usuario.rol), texto: 'Volver a mi trabajo' }] : [])]
  const enlaces = idSeccion === 'publico' ? todos.filter((e) => !RUTAS_DE_ACCESO.includes(e.to)) : todos
  const accesos = idSeccion === 'publico' && !usuario ? todos.filter((e) => RUTAS_DE_ACCESO.includes(e.to)) : []
  const tieneMiCuenta = usuario && (usuario.rol === ROLES.CLIENTE || usuario.rol === ROLES.EMPRESARIO)
  // A dónde lleva el nombre del usuario: su cuenta (cliente y empresario) o solo cambiar su contraseña (personal).
  let destinoIdentidad = null
  if (tieneMiCuenta) destinoIdentidad = { to: '/cliente/cuenta', etiqueta: 'Mi cuenta' }
  else if (usuario && idSeccion !== 'publico') destinoIdentidad = { to: `${rutaInicioDeRol(usuario.rol)}/contrasena`, etiqueta: 'Cambiar contraseña' }

  const identidad = usuario && (
    <>
      <span className="avatar avatar-chico" aria-hidden="true">
        {iniciales(usuario.nombreCompleto)}
      </span>
      <span className="identidad-texto">
        <span className="identidad-nombre">{usuario.nombreCompleto}</span>
        <span className="identidad-rol">
          {usuario.rol}
          {usuario.sucursal && ` · ${usuario.sucursal.codigo}`}
        </span>
      </span>
    </>
  )

  return (
    <div className={`layout layout-${idSeccion}`}>
      <header className="cabecera">
        <div className="cabecera-fila">
          <Link to="/" className="logo" aria-label="JATSA Cargo, ir al inicio">
            <img src={logo} alt="" width={56} height={56} />
          </Link>
          {idSeccion !== 'publico' && <span className="area">{idSeccion === 'cliente' && esPersonal ? 'Mis encomiendas' : seccion.etiqueta}</span>}
          <nav className="menu" aria-label={`Menú de ${seccion.etiqueta}`}>
            {enlaces.map((enlace, i) => (
              <NavLink
                key={enlace.to}
                to={enlace.to}
                end
                className={[enlace.extra ? 'enlace-extra' : '', enlace.extra && !enlaces[i - 1]?.extra ? 'inicio-extras' : '', enlace.texto === 'Mi panel' ? 'enlace-panel' : '']
                  .filter(Boolean)
                  .join(' ') || undefined}
              >
                {enlace.texto}
              </NavLink>
            ))}
          </nav>
          <div className="acciones-cabecera">
            <BotonTema />
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
                {destinoIdentidad ? (
                  <Link to={destinoIdentidad.to} className="identidad" aria-label={`${destinoIdentidad.etiqueta}, ${usuario.nombreCompleto}`}>
                    {identidad}
                  </Link>
                ) : (
                  <span className="identidad">{identidad}</span>
                )}
                {idSeccion === 'publico' && (
                  <Link to={rutaInicioDeRol(usuario.rol)} className="boton boton-amarillo boton-panel">
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
