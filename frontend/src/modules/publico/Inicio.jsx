import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { ROLES, rutaInicioDeRol } from '../../auth/roles.js'
import logo from '../../assets/logo-jatsa.png'
import Mosaico, { ImagenAzul } from '../../components/Mosaico.jsx'
import { fotoDeRegion } from '../../utils/regiones.js'

const LEMA_LUGARES = 'Moquegua · Arequipa · Lima · Ilo'

const MOSAICO = ['Cusco', 'Arequipa', 'Puno', 'Moquegua', 'Lima', 'Tacna', 'Ica', 'La Libertad', 'Loreto', 'Amazonas', 'Cajamarca', 'Piura']

// Diapositiva 2 del prototipo: portada con accesos a salidas, cotizador, agencias y reserva.
export default function Inicio() {
  const { usuario } = useAuth()

  const esCliente = usuario && (usuario.rol === ROLES.CLIENTE || usuario.rol === ROLES.EMPRESARIO)
  const reserva = esCliente
    ? { to: '/cliente/nueva-reserva', texto: 'Nueva reserva' }
    : usuario
      ? { to: rutaInicioDeRol(usuario.rol), texto: 'Ir a mi panel' }
      : { to: '/ingresar', texto: 'Ingresar' }

  const tarjetas = [
    { icono: 23, titulo: 'Próximas salidas', detalle: 'Horarios habituales entre sucursales', to: '/salidas', texto: 'Ver salidas' },
    { icono: 13, titulo: 'Cotiza tu envío', detalle: 'Precio por peso, tipo y ruta', to: '/cotizador', texto: 'Cotizar' },
    { icono: 12, titulo: 'Encuentra una agencia', detalle: 'Dirección y teléfonos', to: '/agencias', texto: 'Ver agencias' },
    { icono: 1, titulo: 'Reserva en línea', detalle: 'Ingresa y deja tu reserva lista', ...reserva },
  ]

  return (
    <div className="inicio inicio-rejilla">
      <div className="inicio-principal">
      <section className="hero hero-mosaico" aria-labelledby="titulo-inicio">
        <div className="hero-texto">
          <img className="hero-logo" src={logo} alt="Jatsa Cargo" />
          <h1 id="titulo-inicio">Envía, rastrea y mira cuándo sale tu encomienda</h1>
          <p className="hero-lugares">{LEMA_LUGARES}</p>
          <div className="hero-acciones">
            <Link to="/cotizador" className="boton boton-amarillo">
              Cotizar
            </Link>
          </div>
        </div>
        <ul className="mosaico" aria-label="Regiones del Perú">
          {MOSAICO.map((region, i) => {
            const foto = fotoDeRegion(region)
            return (
              foto && (
                <li key={region} className={`mosaico-celda m${i + 1}`}>
                  <img src={foto.url} alt={`${foto.lugar}, ${region}`} loading={i < 4 ? 'eager' : 'lazy'} />
                  <span>{region}</span>
                </li>
              )
            )
          })}
        </ul>
      </section>

      <Mosaico ids={[62, 63, 64, 66, 67, 68]} className="alto" />
      </div>

      <section className="tarjetas-acceso" aria-label="Servicios">
        {tarjetas.map((tarjeta) => (
          <article key={tarjeta.titulo} className="tarjeta-acceso">
            <div className="tarjeta-acceso-cabecera">
              <span className="celda-azul tipo-icono icono-tarjeta">
                <ImagenAzul id={tarjeta.icono} />
              </span>
              <div>
                <h3>{tarjeta.titulo}</h3>
                <p>{tarjeta.detalle}</p>
              </div>
            </div>
            <Link to={tarjeta.to} className="boton boton-azul">
              {tarjeta.texto}
            </Link>
          </article>
        ))}
      </section>
    </div>
  )
}
