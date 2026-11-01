import { Link, Route, Routes } from 'react-router-dom'
import RutaProtegida from '../auth/RutaProtegida.jsx'
import PantallaPendiente from '../components/PantallaPendiente.jsx'
import LayoutAdministrador from '../layouts/LayoutAdministrador.jsx'
import LayoutCliente from '../layouts/LayoutCliente.jsx'
import LayoutGerente from '../layouts/LayoutGerente.jsx'
import LayoutPublico from '../layouts/LayoutPublico.jsx'
import LayoutTransportista from '../layouts/LayoutTransportista.jsx'
import Caja from '../modules/administrador/Caja.jsx'
import EnvioRegistrado from '../modules/administrador/EnvioRegistrado.jsx'
import FormalizarEnvio from '../modules/administrador/FormalizarEnvio.jsx'
import ReservaVentanilla from '../modules/administrador/ReservaVentanilla.jsx'
import Ventanilla from '../modules/administrador/Ventanilla.jsx'
import Agencias from '../modules/publico/Agencias.jsx'
import MiPanel from '../modules/cliente/MiPanel.jsx'
import MisPagos from '../modules/cliente/MisPagos.jsx'
import MisReservas from '../modules/cliente/MisReservas.jsx'
import NuevaReserva from '../modules/cliente/NuevaReserva.jsx'
import ReservaRegistrada from '../modules/cliente/ReservaRegistrada.jsx'
import MiCuenta from '../modules/cuenta/MiCuenta.jsx'
import Cotizador from '../modules/publico/Cotizador.jsx'
import CrearCuenta from '../modules/publico/CrearCuenta.jsx'
import Ingresar from '../modules/publico/Ingresar.jsx'
import Inicio from '../modules/publico/Inicio.jsx'
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
  'administrador/': Ventanilla,
  'administrador/sin-reserva': ReservaVentanilla,
  'administrador/formalizar-envio/:codigo': FormalizarEnvio,
  'administrador/envio/:codigo': EnvioRegistrado,
  'administrador/caja': Caja,
  'cliente/': MiPanel,
  'cliente/pagos': MisPagos,
  'cliente/nueva-reserva': NuevaReserva,
  'cliente/reserva-registrada/:codigo': ReservaRegistrada,
  'cliente/reservas': MisReservas,
  'cliente/cuenta': MiCuenta,
  'publico/crear-cuenta': CrearCuenta,
  'publico/': Inicio,
  'publico/agencias': Agencias,
  'publico/cotizador': Cotizador,
  'publico/ingresar': Ingresar,
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

// El área pública está abierta; las demás exigen sesión y uno de los roles de la sección.
export default function RutasApp() {
  return (
    <Routes>
      {SECCIONES.map((seccion) => {
        const Layout = LAYOUTS[seccion.id]
        const marco =
          seccion.id === 'publico' ? (
            <Layout />
          ) : (
            <RutaProtegida roles={seccion.roles}>
              <Layout />
            </RutaProtegida>
          )
        return (
          <Route key={seccion.id} path={seccion.base} element={marco}>
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
