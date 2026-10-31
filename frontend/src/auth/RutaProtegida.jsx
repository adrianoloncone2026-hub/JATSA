import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from './AuthProvider.jsx'
import { rutaInicioDeRol } from './roles.js'

// Protege un área por rol. Es solo comodidad de navegación: el backend vuelve a comprobar
// la sesión y el rol en cada petición.
//   sin sesión          → /ingresar (recordando a dónde quería ir)
//   con otro rol        → su propia pantalla inicial
export default function RutaProtegida({ roles, children }) {
  const { usuario, cargando } = useAuth()
  const ubicacion = useLocation()

  if (cargando) return <p role="status" className="cargando">Verificando sesión…</p>

  if (!usuario) return <Navigate to="/ingresar" replace state={{ desde: ubicacion.pathname }} />

  if (!roles.includes(usuario.rol)) return <Navigate to={rutaInicioDeRol(usuario.rol)} replace />

  return children
}
