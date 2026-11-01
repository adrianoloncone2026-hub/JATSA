import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { listarSalidas, obtenerAgencias } from '../../api/publico.js'

const TIPOS = ['SOBRE', 'PAQUETE', 'CARGA']

export function textoHorario(salida) {
  if (salida.horarios.length > 0) return salida.horarios.join(' · ')
  return salida.programado ? 'programado' : 'sin salidas aún'
}

// Diapositiva 4 del prototipo: salidas entre sucursales. El horario habitual se calcula con las salidas reales de los últimos
// viajes de cada ruta. Los filtros van en la dirección (?origen=&destino=&tipo=) para poder enlazarlos desde otras pantallas.
export default function Salidas() {
  const [parametros, setParametros] = useSearchParams()
  const origen = parametros.get('origen') ?? ''
  const destino = parametros.get('destino') ?? ''
  const tipo = parametros.get('tipo') ?? ''
  const [agencias, setAgencias] = useState([])
  const [estado, setEstado] = useState({ fase: 'cargando', salidas: [], mensaje: '' }) // cargando | listo | error
  const [intento, setIntento] = useState(0)

  // Las agencias llenan los filtros; si fallan, los filtros quedan sin opciones pero la lista sigue funcionando.
  useEffect(() => {
    const control = new AbortController()
    obtenerAgencias(control.signal).then(setAgencias).catch(() => {})
    return () => control.abort()
  }, [])

  // Cada cambio de filtro cancela la consulta anterior: una respuesta lenta no pisa a la del filtro actual.
  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarSalidas({ origen, destino, tipo }, control.signal)
      .then((salidas) => setEstado({ fase: 'listo', salidas, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setEstado({ fase: 'error', salidas: [], mensaje: fallo.message })
      })
    return () => control.abort()
  }, [origen, destino, tipo, intento])

  function cambiar(campo, valor) {
    const siguiente = new URLSearchParams(parametros)
    if (valor) siguiente.set(campo, valor)
    else siguiente.delete(campo)
    setParametros(siguiente, { replace: true })
  }

  return (
    <section className="salidas">
      <h1>Salidas entre sucursales</h1>
      <p className="ayuda">Horario habitual calculado con las salidas reales de los últimos viajes de cada ruta.</p>

      <div className="panel filtros-salidas">
        <div className="campo">
          <label htmlFor="filtroOrigen">Origen</label>
          <select id="filtroOrigen" value={origen} onChange={(e) => cambiar('origen', e.target.value)}>
            <option value="">Todas</option>
            {agencias.map((a) => (
              <option key={a.codigo} value={a.codigo}>
                {a.nombre}
              </option>
            ))}
          </select>
        </div>
        <div className="campo">
          <label htmlFor="filtroDestino">Destino</label>
          <select id="filtroDestino" value={destino} onChange={(e) => cambiar('destino', e.target.value)}>
            <option value="">Todas</option>
            {agencias.map((a) => (
              <option key={a.codigo} value={a.codigo}>
                {a.nombre}
              </option>
            ))}
          </select>
        </div>
        <div className="campo">
          <label htmlFor="filtroTipo">Tipo</label>
          <select id="filtroTipo" value={tipo} onChange={(e) => cambiar('tipo', e.target.value)}>
            <option value="">Todos</option>
            {TIPOS.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        </div>
      </div>

      {estado.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando las salidas…
        </p>
      )}
      {estado.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar las salidas.</strong> {estado.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {estado.fase === 'listo' && estado.salidas.length === 0 && (
        <div className="panel estado-vacio">
          <p>No hay salidas que coincidan con esos filtros.</p>
        </div>
      )}
      {estado.fase === 'listo' && estado.salidas.length > 0 && (
        <ul className="lista-salidas">
          {estado.salidas.map((s) => (
            <li key={s.id} className="panel salida-fila">
              <strong className="salida-ruta">
                {s.origen.nombre} → {s.destino.nombre}
              </strong>
              <span className="salida-detalle">
                Sale: {textoHorario(s)} · {s.horas} h · desde S/ {s.desde}
              </span>
              <Link to={`/salidas/${s.id}`} className="boton boton-azul" aria-label={`Ver la salida ${s.origen.nombre} a ${s.destino.nombre}`}>
                Ver
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
