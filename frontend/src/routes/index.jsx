import { Link, Route, Routes } from 'react-router-dom'
import PantallaPendiente from '../components/PantallaPendiente.jsx'
import LayoutAdministrador from '../layouts/LayoutAdministrador.jsx'
import LayoutCliente from '../layouts/LayoutCliente.jsx'
import LayoutGerente from '../layouts/LayoutGerente.jsx'
import LayoutPublico from '../layouts/LayoutPublico.jsx'
import LayoutTransportista from '../layouts/LayoutTransportista.jsx'
import PruebaConexion from '../modules/publico/PruebaConexion.jsx'
import { SECCIONES } from './definicion.js'

const LAYOUTS = {
  publico: LayoutPublico,
  cliente: LayoutCliente,
  administrador: LayoutAdministrador,
  transportista: LayoutTransportista,
  gerente: LayoutGerente,
}

// Pantallas ya reales; el resto de las rutas muestran un marcador.
const PANTALLAS_REALES = {
  'publico/prueba-conexion': PruebaConexion,
}

function NoEncontrada() {
  return (
    <section className="pendiente">
      <h1>Página no encontrada</h1>
      <p>
        <Link to="/">Volver al inicio</Link>
      </p>
    </section>
  )
}

function elementoDe(seccion, pantalla) {
  const Real = PANTALLAS_REALES[`${seccion.id}/${pantalla.ruta}`]
  if (Real) return <Real />
  return <PantallaPendiente titulo={pantalla.titulo} modulo={pantalla.modulo} seccion={seccion.etiqueta} />
}

// Sin protección por rol: la autenticación llega en el módulo 1.
export default function RutasApp() {
  return (
    <Routes>
      {SECCIONES.map((seccion) => {
        const Layout = LAYOUTS[seccion.id]
        return (
          <Route key={seccion.id} path={seccion.base} element={<Layout />}>
            {seccion.pantallas.map((pantalla) =>
              pantalla.ruta === '' ? (
                <Route key="indice" index element={elementoDe(seccion, pantalla)} />
              ) : (
                <Route key={pantalla.ruta} path={pantalla.ruta} element={elementoDe(seccion, pantalla)} />
              ),
            )}
            {seccion.id === 'publico' && <Route path="*" element={<NoEncontrada />} />}
          </Route>
        )
      })}
    </Routes>
  )
}
