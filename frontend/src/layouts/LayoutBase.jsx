import { Link, NavLink, Outlet } from 'react-router-dom'
import logo from '../assets/logo-jatsa.png'
import { useAuth } from '../auth/AuthProvider.jsx'
import { ROLES, rutaInicioDeRol } from '../auth/roles.js'
import { useCerrarSesion } from '../auth/useCerrarSesion.js'
import { enlacesMenu, seccionPorId } from '../routes/definicion.js'
import { iniciales } from '../utils/formato.js'

const RUTAS_DE_ACCESO = ['/ingresar', '/crear-cuenta']

// Marco común de todos los layouts: cabecera con logo, menú y acciones, y el contenido de la pantalla.
// En el área pública, «Crear cuenta» e «Ingresar» van como botones a la derecha (como en el prototipo);
// con sesión abierta se muestran el usuario y «Salir». Cliente y empresario entran a «Mi cuenta» desde su nombre.
export default function LayoutBase({ idSeccion }) {
  const seccion = seccionPorId(idSeccion)
  const { usuario } = useAuth()
  const { cerrarSesion, saliendo, errorSalida } = useCerrarSesion()

  const todos = enlacesMenu(idSeccion)
  const enlaces = idSeccion === 'publico' ? todos.filter((e) => !RUTAS_DE_ACCESO.includes(e.to)) : todos
  const accesos = idSeccion === 'publico' && !usuario ? todos.filter((e) => RUTAS_DE_ACCESO.includes(e.to)) : []
  const tieneMiCuenta = usuario && (usuario.rol === ROLES.CLIENTE || usuario.rol === ROLES.EMPRESARIO)
  // El personal (gerente, administrador y transportista) entra desde su nombre a «Cambiar contraseña»: solo la contraseña propia,
  // sin datos personales ni funciones de cliente.
  const rutaContrasena = usuario && !tieneMiCuenta && idSeccion !== 'publico' ? `${seccion.base}/contrasena` : null

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
                {tieneMiCuenta ? (
                  <Link to="/cliente/cuenta" className="identidad" aria-label={`Mi cuenta, ${usuario.nombreCompleto}`}>
                    {identidad}
                  </Link>
                ) : rutaContrasena ? (
                  <Link to={rutaContrasena} className="identidad" aria-label={`Cambiar contraseña, ${usuario.nombreCompleto}`}>
                    {identidad}
                  </Link>
                ) : (
                  <span className="identidad">{identidad}</span>
                )}
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
