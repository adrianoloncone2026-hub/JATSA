import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { obtenerAgencias } from '../../api/publico.js'
import ilustracion from '../../assets/ilustracion-sur.png'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { ROLES, rutaInicioDeRol } from '../../auth/roles.js'
import Moneda from '../../components/Moneda.jsx'
import { nombreCorto } from '../../utils/formato.js'

// Código de seguimiento: «EN» + 10 letras o dígitos (restricción CK_ENVIO_CODIGO del script SQL).
const FORMATO_CODIGO = /^EN[A-Z0-9]{10}$/

// Diapositiva 2 del prototipo: buscador de rastreo y accesos a salidas, cotizador, agencias y reserva.
export default function Inicio() {
  const navegar = useNavigate()
  const { usuario } = useAuth()
  const [codigo, setCodigo] = useState('')
  const [error, setError] = useState('')
  const [lugares, setLugares] = useState([])

  // La lista de lugares sale de las agencias activas; si falla, simplemente no se muestra.
  useEffect(() => {
    const control = new AbortController()
    obtenerAgencias(control.signal)
      .then((agencias) => setLugares(agencias.map(nombreCorto)))
      .catch(() => {})
    return () => control.abort()
  }, [])

  function rastrear(evento) {
    evento.preventDefault()
    const normalizado = codigo.trim().toUpperCase()
    if (!FORMATO_CODIGO.test(normalizado)) {
      setError('El código de seguimiento empieza con EN y tiene 12 caracteres. Por ejemplo: ENZ5H0D3V7NJ.')
      return
    }
    setError('')
    navegar(`/rastreo?codigo=${encodeURIComponent(normalizado)}`)
  }

  const esCliente = usuario && (usuario.rol === ROLES.CLIENTE || usuario.rol === ROLES.EMPRESARIO)
  const reserva = esCliente
    ? { to: '/cliente/nueva-reserva', texto: 'Nueva reserva' }
    : usuario
      ? { to: rutaInicioDeRol(usuario.rol), texto: 'Ir a mi panel' }
      : { to: '/ingresar', texto: 'Ingresar' }

  const tarjetas = [
    { icono: 'casa', titulo: 'Próximas salidas', detalle: 'Horarios habituales entre sucursales', to: '/salidas', texto: 'Ver salidas' },
    { icono: 'edificio', titulo: 'Cotiza tu envío', detalle: 'Precio por peso, tipo y ruta', to: '/cotizador', texto: 'Cotizar' },
    { icono: 'iglesia', titulo: 'Encuentra una agencia', detalle: 'Dirección y teléfonos', to: '/agencias', texto: 'Ver agencias' },
    { icono: 'casa', titulo: 'Reserva en línea', detalle: 'Ingresa y deja tu reserva lista', ...reserva },
  ]

  return (
    <div className="inicio">
      <section className="hero" aria-labelledby="titulo-inicio">
        <div className="hero-texto">
          <h1 id="titulo-inicio">Envía, rastrea y mira cuándo sale tu encomienda</h1>
          {lugares.length > 0 && <p className="hero-lugares">{lugares.join(' · ')}</p>}
        </div>
        <img className="hero-ilustracion" src={ilustracion} alt="" />
      </section>

      <form className="tarjeta-rastreo" onSubmit={rastrear} noValidate>
        <h2>Rastrea tu envío</h2>
        <div className="rastreo-fila">
          <div className="campo">
            <label htmlFor="codigo-seguimiento">Código de seguimiento</label>
            <input
              id="codigo-seguimiento"
              type="text"
              autoComplete="off"
              spellCheck={false}
              maxLength={12}
              value={codigo}
              onChange={(e) => setCodigo(e.target.value)}
              aria-invalid={error !== ''}
              aria-describedby={error ? 'error-codigo' : undefined}
            />
          </div>
          <button type="submit" className="boton boton-marino">
            Rastrear
          </button>
        </div>
        {error && (
          <p id="error-codigo" className="mensaje-error" role="alert">
            {error}
          </p>
        )}
      </form>

      <section className="tarjetas-acceso" aria-label="Servicios">
        {tarjetas.map((tarjeta) => (
          <article key={tarjeta.titulo} className="tarjeta-acceso">
            <div className="tarjeta-acceso-cabecera">
              <Moneda icono={tarjeta.icono} />
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
