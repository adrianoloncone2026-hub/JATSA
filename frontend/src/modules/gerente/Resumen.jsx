import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { listarSucursales } from '../../api/catalogos.js'
import { obtenerResumen } from '../../api/reportes.js'
import TarjetaIndicador from '../../components/TarjetaIndicador.jsx'

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre']
const GRUPOS = [
  ['ENTREGADO', 'Entregados'],
  ['EN_TRANSITO', 'En tránsito'],
  ['EN_DESTINO', 'En agencia de destino'],
  ['REGISTRADO', 'Registrados'],
]

function mesActual() {
  const hoy = new Date()
  return `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}`
}

function nombreMes(mes) {
  const [anio, numero] = mes.split('-')
  const nombre = MESES[Number(numero) - 1]
  return `${nombre.charAt(0).toUpperCase()}${nombre.slice(1)} ${anio}`
}

function soles(monto) {
  return `S/ ${Number(monto).toLocaleString('en-US', { maximumFractionDigits: 2 })}`
}

// Diapositiva 26: Resumen del GERENTE. Solo lectura: indicadores del mes y dónde están los envíos. Desde aquí se llega a Reportes
// (módulo 12, pendiente) y a Viajes y flota.
export default function Resumen() {
  const [filtros, setFiltros] = useState({ mes: mesActual(), sucursal: '' })
  const [sucursales, setSucursales] = useState([])
  const [estado, setEstado] = useState({ fase: 'cargando', datos: null, mensaje: '' })
  const [intento, setIntento] = useState(0)

  useEffect(() => {
    const control = new AbortController()
    listarSucursales(control.signal)
      .then((datos) => setSucursales(datos.sucursales))
      .catch(() => {}) // sin la lista, el filtro queda en «Todas»; el resumen sigue funcionando
    return () => control.abort()
  }, [])

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    obtenerResumen(filtros, control.signal)
      .then((datos) => setEstado({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name !== 'AbortError') setEstado({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [filtros, intento])

  const { datos } = estado
  const sinEnvios = datos !== null && datos.enviosRegistrados.total === 0

  return (
    <section className="resumen-gerente">
      <h1>{datos ? `Resumen de ${nombreMes(datos.mes).split(' ')[0].toLowerCase()}` : 'Resumen'}</h1>

      <div className="filtros" role="group" aria-label="Filtros del resumen">
        <label>
          Mes
          <input type="month" value={filtros.mes} max={mesActual()} onChange={(e) => e.target.value && setFiltros({ ...filtros, mes: e.target.value })} />
        </label>
        <label>
          Sucursal
          <select value={filtros.sucursal} onChange={(e) => setFiltros({ ...filtros, sucursal: e.target.value })}>
            <option value="">Todas</option>
            {sucursales.map((s) => (
              <option key={s.codigo} value={s.codigo}>
                {s.nombre}
              </option>
            ))}
          </select>
        </label>
        <Link className="boton boton-claro" to="/gerente/reportes">
          Reportes
        </Link>
        <Link className="boton boton-claro" to="/gerente/viajes-flota">
          Viajes y flota
        </Link>
      </div>

      {estado.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando el resumen…
        </p>
      )}
      {estado.fase === 'error' && (
        <div role="alert" className="mensaje-error">
          <p>{estado.mensaje}</p>
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}

      {estado.fase === 'listo' && datos && (
        <>
          <div className="tarjetas-indicadores">
            <TarjetaIndicador valor={datos.enviosRegistrados.total} titulo="envíos registrados" variacion={datos.enviosRegistrados.variacionPct} referencia="el mes anterior" />
            <TarjetaIndicador valor={soles(datos.cobrado.total)} titulo="cobrados" variacion={datos.cobrado.variacionPct} referencia="el mes anterior" />
            <TarjetaIndicador valor={datos.porEntregar.total} titulo="envíos por entregar" detalle={`${datos.porEntregar.masDeTresDias} llevan más de 3 días`} />
            <TarjetaIndicador valor={datos.viajes.enRuta} titulo="viajes en ruta" detalle={`${datos.viajes.programados} programados`} />
          </div>

          <h2>Dónde están los envíos del mes</h2>
          {sinEnvios ? (
            <div className="panel estado-vacio">
              <p>No hay envíos registrados en este mes.</p>
            </div>
          ) : (
            <dl className="grupos-envios">
              {GRUPOS.map(([clave, etiqueta]) => (
                <div key={clave} className="grupo-envios">
                  <dt>{etiqueta}</dt>
                  <dd>{datos.dondeEstan[clave]}</dd>
                </div>
              ))}
            </dl>
          )}
        </>
      )}
    </section>
  )
}
