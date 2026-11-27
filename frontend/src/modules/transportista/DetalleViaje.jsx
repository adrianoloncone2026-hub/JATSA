import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { marcarLlegada, marcarSalida, obtenerViaje } from '../../api/viajes.js'
import Mosaico from '../../components/Mosaico.jsx'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'
import RutaMonedas from '../../components/RutaMonedas.jsx'
import TablaCarga from '../../components/TablaCarga.jsx'
import { useAgencias } from '../../hooks/useAgencias.js'
import { fechaHoraBreve, horaCorta, kilosSinCeros } from '../../utils/formato.js'

// Diapositiva 25 del prototipo: un viaje del transportista con su carga, y los botones «Marcar salida» y «Marcar llegada».
export default function DetalleViaje() {
  const { codigo } = useParams()
  const [estado, setEstado] = useState({ fase: 'cargando', viaje: null, mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [pendiente, setPendiente] = useState(null)
  const [enviando, setEnviando] = useState(false)
  const [aviso, setAviso] = useState({ tipo: '', texto: '' })
  const envioEnCurso = useRef(false)
  const { porCodigo } = useAgencias()

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: anterior.viaje ? 'listo' : 'cargando' }))
    obtenerViaje(codigo, control.signal)
      .then((viaje) => setEstado({ fase: 'listo', viaje, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: fallo.estado === 404 ? 'no_encontrado' : 'error', viaje: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [codigo, intento])

  async function registrar(accion) {
    // Resguardo contra doble clic: el estado `enviando` se actualiza tras el render, esta bandera es inmediata.
    if (envioEnCurso.current) return
    envioEnCurso.current = true
    setEnviando(true)
    setAviso({ tipo: '', texto: '' })
    try {
      const viaje = await (accion === 'salida' ? marcarSalida(codigo) : marcarLlegada(codigo))
      setEstado({ fase: 'listo', viaje, mensaje: '' })
      setAviso({ tipo: 'ok', texto: accion === 'salida' ? 'Salida registrada. ¡Buen viaje!' : 'Llegada registrada. Los envíos quedaron disponibles en la agencia de destino.' })
    } catch (fallo) {
      setAviso({ tipo: 'error', texto: fallo.message })
      if (fallo.estado === 409 || fallo.estado === 404) setIntento((n) => n + 1)
    } finally {
      envioEnCurso.current = false
      setEnviando(false)
      setPendiente(null)
    }
  }

  if (estado.fase === 'no_encontrado') {
    return (
      <section className="confirmacion panel">
        <h1>No encontramos ese viaje</h1>
        <p>{estado.mensaje}</p>
        <div className="acciones-confirmacion">
          <Link to="/transportista" className="boton boton-azul">
            Volver a Mis viajes
          </Link>
        </div>
      </section>
    )
  }
  if (estado.fase === 'error') {
    return (
      <div className="resultado error" role="alert">
        <strong>No se pudo cargar el viaje.</strong> {estado.mensaje}{' '}
        <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
          Reintentar
        </button>
      </div>
    )
  }
  if (!estado.viaje) {
    return (
      <p role="status" className="cargando">
        Cargando el viaje…
      </p>
    )
  }

  const v = estado.viaje
  const llegada = v.fechaHoraLlegada ? fechaHoraBreve(v.fechaHoraLlegada) : v.llegadaEstimada ? `— (estimada ${horaCorta(v.llegadaEstimada)})` : '—'

  return (
    <section className="detalle-viaje">
      <div className="detalle-viaje-cabecera">
        <h1>
          {v.codigo} · {v.origen.nombre} → {v.destino.nombre}
        </h1>
        <InsigniaEstado estado={v.estado} />
      </div>

      <RutaMonedas
        origen={porCodigo.get(v.origen.codigo)?.departamento ?? ''}
        destino={porCodigo.get(v.destino.codigo)?.departamento ?? ''}
        nombreOrigen={v.origen.nombre}
        nombreDestino={v.destino.nombre}
        tamano={64}
      />

      <ol className="pasos-viaje" aria-label="Avance del viaje">
        {[
          ['PROGRAMADO', 'Programado'],
          ['EN_RUTA', 'En ruta'],
          ['TERMINADO', 'Terminado'],
        ].map(([clave, texto], i, todos) => {
          const actual = todos.findIndex(([c]) => c === v.estado)
          return (
            <li key={clave} className={i === actual ? 'paso actual' : i < actual ? 'paso cumplido' : 'paso'} aria-current={i === actual ? 'step' : undefined}>
              <span className="paso-numero" aria-hidden="true">
                {i < actual ? '✓' : i + 1}
              </span>
              {texto}
            </li>
          )
        })}
      </ol>

      <dl className="datos-viaje panel">
        <div>
          <dt>Vehículo</dt>
          <dd>
            {v.vehiculo.tipo} {v.vehiculo.placa}
          </dd>
        </div>
        <div>
          <dt>Salida</dt>
          <dd>{v.fechaHoraSalida ? fechaHoraBreve(v.fechaHoraSalida) : '—'}</dd>
        </div>
        <div>
          <dt>Llegada</dt>
          <dd>{llegada}</dd>
        </div>
      </dl>

      <h2>Carga</h2>
      {v.carga.length === 0 ? (
        <div className="panel estado-vacio">
          <p>Este viaje no tiene envíos cargados.</p>
        </div>
      ) : (
        <TablaCarga carga={v.carga} />
      )}
      <p className="peso-cargado">
        Peso cargado <strong>{v.pesoCargadoKg}</strong> de <strong>{kilosSinCeros(v.vehiculo.capacidadKg)}</strong> kg
      </p>

      <div className="viaje-lateral">
      <Mosaico vertical ids={[62, 23, 2, 61]} />
      {aviso.texto && (
        <div className={`resultado ${aviso.tipo === 'ok' ? 'ok' : 'error'}`} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
          {aviso.texto}
        </div>
      )}

      {pendiente ? (
        <div className="confirmar-viaje panel" role="group" aria-label={`Confirmar la ${pendiente}`}>
          <p>
            {pendiente === 'salida' ? '¿Marcar la salida ahora? Quedará registrada con la hora actual y no se puede deshacer.' : '¿Marcar la llegada ahora? Quedará registrada con la hora actual y no se puede deshacer.'}
            {pendiente === 'salida' && v.cantidadEnvios === 0 ? ' Este viaje sale sin carga.' : ''}
          </p>
          <div className="acciones-formulario">
            <button type="button" className="boton boton-azul" onClick={() => registrar(pendiente)} disabled={enviando}>
              {enviando ? 'Registrando…' : 'Sí, marcar'}
            </button>
            <button type="button" className="boton boton-claro" onClick={() => setPendiente(null)} disabled={enviando}>
              No
            </button>
          </div>
        </div>
      ) : (
        <div className="acciones-viaje panel">
          <div className="acciones-formulario">
            <button type="button" className="boton boton-azul boton-grande" disabled={v.estado !== 'PROGRAMADO'} onClick={() => setPendiente('salida')}>
              {v.estado === 'PROGRAMADO' ? 'Marcar salida' : '✓ Salida registrada'}
            </button>
            <button type="button" className="boton boton-marino boton-grande" disabled={v.estado !== 'EN_RUTA'} onClick={() => setPendiente('llegada')}>
              {v.estado === 'TERMINADO' ? '✓ Llegada registrada' : 'Marcar llegada'}
            </button>
            <Link to="/transportista" className="boton boton-claro">
              Mis viajes
            </Link>
          </div>
          <p className="ayuda">
            {v.estado === 'PROGRAMADO' && 'Marca la salida cuando el vehículo parta de la agencia.'}
            {v.estado === 'EN_RUTA' && 'La llegada la confirmas tú al llegar a la agencia de destino: la hora estimada es solo una referencia y nunca se registra sola.'}
            {v.estado === 'TERMINADO' && 'Este viaje ya terminó; la salida y la llegada no se pueden registrar otra vez.'}
          </p>
        </div>
      )}
      </div>
    </section>
  )
}
