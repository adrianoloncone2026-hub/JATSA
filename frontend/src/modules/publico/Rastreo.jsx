import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { rastrearEnvio } from '../../api/publico.js'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import { fechaHoraBreve, horaCorta } from '../../utils/formato.js'

// Código de seguimiento: «EN» + 10 letras o dígitos (restricción CK_ENVIO_CODIGO del script SQL).
const FORMATO_CODIGO = /^EN[A-Z0-9]{10}$/
const ETIQUETA_ETAPA = { REGISTRADO: 'REGISTRADO', EN_TRANSITO: 'EN TRÁNSITO', EN_DESTINO: 'EN DESTINO', ENTREGADO: 'ENTREGADO' }

// Diapositiva 3 del prototipo: rastreo público por código de seguimiento, con estado, recorrido y llegada estimada.
// El código va en la dirección (?codigo=) para poder enlazarlo. No muestra documentos, teléfonos ni nombres.
export default function Rastreo() {
  const [parametros, setParametros] = useSearchParams()
  const codigoDeLaDireccion = (parametros.get('codigo') ?? '').trim().toUpperCase()
  const [escrito, setEscrito] = useState(codigoDeLaDireccion)
  const [error, setError] = useState('')
  const [estado, setEstado] = useState({ fase: 'inactivo', envio: null, mensaje: '' }) // inactivo | cargando | listo | no_encontrado | error
  const [intento, setIntento] = useState(0)

  // Cada código nuevo cancela la consulta anterior: una respuesta lenta no pisa a la del código actual.
  useEffect(() => {
    if (!FORMATO_CODIGO.test(codigoDeLaDireccion)) {
      setEstado({ fase: 'inactivo', envio: null, mensaje: '' })
      return undefined
    }
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    rastrearEnvio(codigoDeLaDireccion, control.signal)
      .then((envio) => setEstado({ fase: 'listo', envio, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: fallo.estado === 404 ? 'no_encontrado' : 'error', envio: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [codigoDeLaDireccion, intento])

  function rastrear(evento) {
    evento.preventDefault()
    const normalizado = escrito.trim().toUpperCase()
    if (!FORMATO_CODIGO.test(normalizado)) {
      setError('El código de seguimiento empieza con EN y tiene 12 caracteres. Por ejemplo: ENZ5H0D3V7NJ.')
      return
    }
    setError('')
    if (normalizado === codigoDeLaDireccion) setIntento((n) => n + 1)
    else setParametros({ codigo: normalizado })
  }

  const envio = estado.fase === 'listo' ? estado.envio : null

  return (
    <section className="rastreo">
      <h1>Rastrea tu envío</h1>

      <form className="panel buscar-reserva" onSubmit={rastrear} noValidate>
        <div className="campo">
          <label htmlFor="codigoSeguimiento">Código de seguimiento</label>
          <input id="codigoSeguimiento" type="text" autoComplete="off" maxLength={12} placeholder="ENZ5H0D3V7NJ" value={escrito} aria-invalid={error ? 'true' : undefined} onChange={(e) => setEscrito(e.target.value)} />
          {error && <p className="campo-error">{error}</p>}
        </div>
        <button type="submit" className="boton boton-azul">
          {estado.fase === 'cargando' ? 'Buscando…' : 'RASTREAR'}
        </button>
      </form>

      {estado.fase === 'cargando' && !envio && (
        <p role="status" className="cargando">
          Buscando tu envío…
        </p>
      )}
      {estado.fase === 'no_encontrado' && (
        <div className="resultado error" role="alert">
          {estado.mensaje}
        </div>
      )}
      {estado.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudo consultar el envío.</strong> {estado.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}

      {envio && (
        <div className="rastreo-resultado">
          <article className="panel rastreo-estado" aria-label={`Envío ${envio.codigoSeguimiento}`}>
            <div className="rastreo-cabecera">
              <h2>{envio.codigoSeguimiento}</h2>
              <InsigniaEstado estado={envio.estado} />
            </div>
            <p className="ruta-texto">
              {envio.origen.nombre} → {envio.destino.nombre} · {envio.cantidadBultos} {envio.cantidadBultos === 1 ? 'bulto' : 'bultos'} · {envio.pesoTotalKg} kg · Saldo S/ {envio.saldo}
            </p>

            {envio.estado === 'DEVUELTO' && <p className="mensaje-aviso">Este envío fue devuelto.</p>}
            {envio.estado === 'ANULADO' && <p className="mensaje-aviso">Este envío fue anulado.</p>}
            {envio.estado !== 'DEVUELTO' && envio.estado !== 'ANULADO' && (
              <ol className="etapas" aria-label="Etapas del envío">
                {envio.etapas.map((etapa, i) => (
                  <li key={etapa.estado} className={etapa.actual ? 'etapa actual' : etapa.cumplida ? 'etapa cumplida' : 'etapa'} aria-current={etapa.actual ? 'step' : undefined}>
                    <span className="etapa-numero" aria-hidden="true">
                      {etapa.cumplida && !etapa.actual ? '✓' : i + 1}
                    </span>
                    {ETIQUETA_ETAPA[etapa.estado]}
                  </li>
                ))}
              </ol>
            )}

            <h3>Recorrido</h3>
            <ol className="recorrido">
              {envio.recorrido.map((hito, i) => (
                <li key={`${hito.fechaHora}-${i}`} className={hito.tipo === 'estimada' ? 'hito estimada' : 'hito'}>
                  <time dateTime={hito.fechaHora.replace(' ', 'T')}>{hito.tipo === 'estimada' ? `${fechaHoraBreve(hito.fechaHora).slice(0, 5)} ≈${horaCorta(hito.fechaHora)}` : fechaHoraBreve(hito.fechaHora)}</time>
                  <span>{hito.texto}</span>
                </li>
              ))}
            </ol>

            <div className="acciones-formulario">
              <Link to={`/salidas/${envio.rutaId}`} className="boton boton-claro">
                Ver horarios de esta ruta
              </Link>
              <Link to={`/agencias?agencia=${encodeURIComponent(envio.destino.codigo)}`} className="boton boton-claro">
                Ver agencia de destino
              </Link>
            </div>
          </article>

          <aside className="panel rastreo-viaje" aria-label="Viaje en curso">
            <h2>
              {envio.rutaId
                ? `${nombreCorto(envio.viajeActual ? envio.viajeActual.origen.nombre : envio.origen.nombre)} → ${nombreCorto(envio.viajeActual ? envio.viajeActual.destino.nombre : envio.destino.nombre)}`
                : 'Ruta'}
            </h2>
            {envio.viajeActual ? (
              <p>
                Viaje {envio.viajeActual.codigo} · {envio.viajeActual.vehiculo.tipo} {envio.viajeActual.vehiculo.placa} · salió {horaCorta(envio.viajeActual.fechaHoraSalida)} · en ruta
              </p>
            ) : (
              <p className="ayuda">{envio.estado === 'ENTREGADO' ? 'El envío ya fue entregado.' : 'Ahora no va en ningún viaje.'}</p>
            )}
            <Link to={`/salidas/${envio.rutaId}`} className="boton boton-marino">
              Salidas frecuentes {nombreCorto(envio.viajeActual ? envio.viajeActual.origen.nombre : envio.origen.nombre)} → {nombreCorto(envio.viajeActual ? envio.viajeActual.destino.nombre : envio.destino.nombre)}
            </Link>
          </aside>
        </div>
      )}
    </section>
  )
}

// «Agencia Arequipa Centro» → «Arequipa» (la ciudad, como en la diapositiva).
function nombreCorto(nombre) {
  return nombre.replace(/^Agencia\s+/i, '').split(' ')[0]
}
