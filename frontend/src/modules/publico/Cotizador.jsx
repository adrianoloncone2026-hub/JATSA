import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { cotizar, obtenerAgencias } from '../../api/publico.js'
import Moneda, { iconoDeDepartamento } from '../../components/Moneda.jsx'

const FORMATO_PESO = /^\d{1,6}([.,]\d{1,2})?$/

// Diapositiva 7 del prototipo: origen, destino y peso → tipo, precio por bulto y tarifas de la ruta.
export default function Cotizador() {
  const [agencias, setAgencias] = useState({ fase: 'cargando', lista: [], mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [campos, setCampos] = useState({ origen: '', destino: '', peso: '' })
  const [error, setError] = useState('')
  const [calculando, setCalculando] = useState(false)
  const [resultado, setResultado] = useState(null)

  // Cotización en curso. Una respuesta solo se acepta si sigue siendo la solicitud vigente: así una
  // respuesta lenta de datos anteriores nunca muestra un precio que no corresponde a lo que hay en el formulario.
  const solicitudVigente = useRef(null)
  const cancelarCotizacion = useCallback(() => {
    solicitudVigente.current?.abort()
    solicitudVigente.current = null
  }, [])
  useEffect(() => cancelarCotizacion, [cancelarCotizacion]) // al salir de la pantalla

  useEffect(() => {
    const control = new AbortController()
    setAgencias((anterior) => ({ ...anterior, fase: 'cargando' }))
    obtenerAgencias(control.signal)
      .then((lista) => setAgencias({ fase: 'listo', lista, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setAgencias({ fase: 'error', lista: [], mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  function cambiar(evento) {
    const { name, value } = evento.target
    setCampos((anteriores) => ({ ...anteriores, [name]: value }))
    // Un resultado (o una cotización en curso) calculado con otros datos podría confundir:
    // al cambiar cualquier campo se cancela lo pendiente y se descarta lo mostrado.
    cancelarCotizacion()
    setCalculando(false)
    setResultado(null)
    setError('')
  }

  function validar() {
    if (!campos.origen) return 'Elige la sucursal de origen.'
    if (!campos.destino) return 'Elige la sucursal de destino.'
    if (campos.origen === campos.destino) return 'El origen y el destino deben ser distintos.'
    if (!FORMATO_PESO.test(campos.peso.trim())) return 'Ingresa el peso en kilogramos, con hasta 2 decimales.'
    if (Number(campos.peso.trim().replace(',', '.')) <= 0) return 'El peso debe ser mayor que 0.'
    return ''
  }

  async function calcular(evento) {
    evento.preventDefault()
    const problema = validar()
    if (problema) {
      setError(problema)
      setResultado(null)
      return
    }
    setError('')

    cancelarCotizacion()
    const control = new AbortController()
    solicitudVigente.current = control
    setCalculando(true)
    try {
      const datos = await cotizar({ ...campos, peso: campos.peso.trim().replace(',', '.') }, control.signal)
      if (solicitudVigente.current !== control) return // respuesta obsoleta: otra solicitud la reemplazó
      setResultado(datos)
    } catch (fallo) {
      if (fallo.name === 'AbortError' || solicitudVigente.current !== control) return
      setResultado(null)
      setError(fallo.message)
    } finally {
      if (solicitudVigente.current === control) {
        solicitudVigente.current = null
        setCalculando(false)
      }
    }
  }

  const departamentoDe = (codigo) => agencias.lista.find((a) => a.codigo === codigo)?.departamento ?? ''

  return (
    <div className="cotizador">
      <section className="panel cotizador-principal">
        <h1>Cotiza tu envío</h1>

        {agencias.fase === 'error' && (
          <div className="resultado error" role="alert">
            <strong>No se pudieron cargar las sucursales.</strong> {agencias.mensaje}{' '}
            <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
              Reintentar
            </button>
          </div>
        )}

        <form onSubmit={calcular} noValidate className="formulario-cotizador">
          <div className="campo">
            <label htmlFor="origen">Sucursal de origen</label>
            <select id="origen" name="origen" value={campos.origen} onChange={cambiar} disabled={agencias.fase !== 'listo'}>
              <option value="">{agencias.fase === 'cargando' ? 'Cargando…' : 'Elige una sucursal'}</option>
              {agencias.lista.map((a) => (
                <option key={a.codigo} value={a.codigo}>
                  {a.nombre} ({a.codigo})
                </option>
              ))}
            </select>
          </div>
          <div className="campo">
            <label htmlFor="destino">Sucursal de destino</label>
            <select id="destino" name="destino" value={campos.destino} onChange={cambiar} disabled={agencias.fase !== 'listo'}>
              <option value="">{agencias.fase === 'cargando' ? 'Cargando…' : 'Elige una sucursal'}</option>
              {agencias.lista.map((a) => (
                <option key={a.codigo} value={a.codigo}>
                  {a.nombre} ({a.codigo})
                </option>
              ))}
            </select>
          </div>
          <div className="campo">
            <label htmlFor="peso">Peso aproximado (kg)</label>
            <input id="peso" name="peso" type="text" inputMode="decimal" autoComplete="off" value={campos.peso} onChange={cambiar} />
          </div>
          <button type="submit" className="boton boton-azul" disabled={calculando || agencias.fase !== 'listo'}>
            {calculando ? 'Calculando…' : 'Calcular'}
          </button>
        </form>

        {error && (
          <p className="mensaje-error" role="alert">
            {error}
          </p>
        )}

        <div aria-live="polite">{resultado && <Resultado datos={resultado} departamentoDe={departamentoDe} />}</div>
      </section>

      <aside className="cotizador-acciones" aria-label="Acciones">
        <Link to="/cliente/nueva-reserva" className="boton boton-marino">
          Reservar ahora
        </Link>
        <Link to="/salidas" className="boton boton-azul">
          Ver salidas
        </Link>
        <Link to="/agencias" className="boton boton-claro">
          Agencias
        </Link>
        <Link to="/" className="boton boton-celeste">
          Volver al inicio
        </Link>
      </aside>
    </div>
  )
}

function Resultado({ datos, departamentoDe }) {
  const aplicable = datos.cotizacion

  return (
    <section className="resultado-cotizacion" aria-label="Resultado de la cotización">
      <div className="resultado-cabecera">
        <div className="ruta-monedas" aria-hidden="true">
          <Moneda icono={iconoDeDepartamento(departamentoDe(datos.origen.codigo))} tamano={72} />
          <span>→</span>
          <Moneda icono={iconoDeDepartamento(departamentoDe(datos.destino.codigo))} tamano={72} />
        </div>
        <div>
          <p className="ruta-texto">
            {datos.origen.nombre} → {datos.destino.nombre}
          </p>
          <h2>
            Tipo {aplicable.tipo} (hasta {aplicable.pesoMaximoKg} kg)
          </h2>
          {aplicable.disponible ? (
            <>
              <p className="precio">S/ {aplicable.precioPorBulto} por bulto</p>
              <p className="formula">
                {aplicable.precioBase} + {aplicable.precioPorKm} × {aplicable.distanciaKm} km = {aplicable.precioPorBulto}
              </p>
            </>
          ) : (
            <p className="mensaje-aviso">
              No hay una ruta vigente de tipo {aplicable.tipo} entre estas sucursales para un bulto de {datos.pesoKg} kg.
            </p>
          )}
        </div>
      </div>

      <table className="tabla-tarifas">
        <caption className="solo-lectores">Tarifas de la ruta por tipo de envío</caption>
        <thead>
          <tr>
            <th scope="col">Tipo</th>
            <th scope="col">Peso máximo</th>
            <th scope="col">Precio</th>
            <th scope="col">Tiempo</th>
          </tr>
        </thead>
        <tbody>
          {datos.tarifas.map((tarifa) => (
            <tr key={tarifa.tipo} className={tarifa.tipo === aplicable.tipo ? 'fila-aplicable' : undefined}>
              <th scope="row">{tarifa.tipo}</th>
              <td>{tarifa.pesoMaximoKg} kg</td>
              <td>{tarifa.disponible ? `S/ ${tarifa.precioPorBulto}` : 'Sin ruta vigente'}</td>
              <td>{tarifa.disponible ? `${tarifa.tiempoHoras} h` : '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
