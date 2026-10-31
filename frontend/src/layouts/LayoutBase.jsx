import { NavLink, Outlet } from 'react-router-dom'
import { SECCIONES, enlacesMenu, seccionPorId } from '../routes/definicion.js'

// Marco común de todos los layouts: cabecera con el nombre del área, menú y contenido.
// El menú de "Áreas" solo existe en desarrollo, para recorrer el esqueleto sin autenticación.
export default function LayoutBase({ idSeccion }) {
  const seccion = seccionPorId(idSeccion)
  const enlaces = enlacesMenu(idSeccion)

  return (
    <div className={`layout layout-${idSeccion}`}>
      <header className="cabecera">
        <div className="cabecera-fila">
          <span className="marca">JATSA</span>
          <span className="area">{seccion.etiqueta}</span>
        </div>
        <nav className="menu" aria-label={`Menú de ${seccion.etiqueta}`}>
          {enlaces.map((enlace) => (
            <NavLink key={enlace.to} to={enlace.to} end>
              {enlace.texto}
            </NavLink>
          ))}
        </nav>
        {import.meta.env.DEV && (
          <nav className="areas-dev" aria-label="Áreas (solo desarrollo)">
            <span>Áreas (solo desarrollo):</span>
            {SECCIONES.map((s) => (
              <NavLink key={s.id} to={s.base} end={false} className={s.id === idSeccion ? 'activa' : ''}>
                {s.etiqueta}
              </NavLink>
            ))}
          </nav>
        )}
      </header>
      <main className="contenido">
        <Outlet />
      </main>
    </div>
  )
}
