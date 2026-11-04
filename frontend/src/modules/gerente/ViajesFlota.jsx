import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { listarSucursales, listarVehiculos } from '../../api/catalogos.js'
import { obtenerViajesFlota } from '../../api/reportes.js'
import { listarCuentas } from '../../api/usuarios.js'
import TarjetaIndicador from '../../components/TarjetaIndicador.jsx'
import { horaCorta, kilosSinCeros } from '../../utils/formato.js'

const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre']
const TIPOS = ['SOBRE', 'PAQUETE', 'CARGA']

function pct(valor) {
  return valor === null ? '—' : `${valor} %`
}

function kg(valor) {
  return `${Number(valor).toLocaleString('en-US', { maximumFractionDigits: 2 })} kg`
}

// Diapositiva 28: Viajes y flota del GERENTE. Solo lectura. El periodo (año y mes) cuenta los viajes terminados; «En ruta ahora»
// y «Programados» son el estado actual.
export default function ViajesFlota() {
  const anioActual = new Date().getFullYear()
  const [filtros, setFiltros] = useState({ anio: String(anioActual), mes: String(new Date().getMonth() + 1), sucursal: '', vehiculo: '', transportista: '', tipoEnvio: '' })
  const [opciones, setOpciones] = useState({ sucursales: [], vehiculos: [], transportistas: [] })
  const [estado, setEstado] = useState({ fase: 'cargando', datos: null, mensaje: '' })
  const [intento, setIntento] = useState(0)

  useEffect(() => {
    const control = new AbortController()
    const { signal } = control
    Promise.all([listarSucursales(signal), listarVehiculos(signal), listarCuentas({ rol: 'TRANSPORTISTA' }, signal)])
      .then(([s, v, t]) => setOpciones({ sucursales: s.sucursales, vehiculos: v.vehiculos, transportistas: t.cuentas }))
      .catch(() => {}) // sin las listas, los filtros quedan en «Todos»
    return () => control.abort()
  }, [])

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    obtenerViajesFlota(filtros, control.signal)
      .then((datos) => setEstado({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name !== 'AbortError') setEstado({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [filtros, intento])

  const cambiar = (campo) => (e) => setFiltros((f) => ({ ...f, [campo]: e.target.value }))
  const anios = Array.from({ length: 5 }, (_, i) => String(anioActual - i))
  const { datos } = estado
  const ind = datos?.indicadores

  return (
    <section className="viajes-flota">
      <p>
        <Link to="/gerente/reportes">← Reportes</Link> · <Link to="/gerente">Resumen</Link>
      </p>
      <h1>Viajes y flota</h1>

      <div className="filtros" role="group" aria-label="Filtros de viajes y flota">
        <label>
          Año
          <select value={filtros.anio} onChange={cambiar('anio')}>
            {anios.map((a) => (
              <option key={a} value={a}>
                {a}
              </option>
            ))}
          </select>
        </label>
        <label>
          Mes
          <select value={filtros.mes} onChange={cambiar('mes')}>
            <option value="">Todos</option>
            {MESES.map((m, i) => (
              <option key={m} value={i + 1}>
                {m}
              </option>
            ))}
          </select>
        </label>
        <label>
          Sucursal
          <select value={filtros.sucursal} onChange={cambiar('sucursal')}>
            <option value="">Todas</option>
            {opciones.sucursales.map((s) => (
              <option key={s.codigo} value={s.codigo}>
                {s.nombre}
              </option>
            ))}
          </select>
        </label>
        <label>
          Vehículo
          <select value={filtros.vehiculo} onChange={cambiar('vehiculo')}>
            <option value="">Todos</option>
            {opciones.vehiculos.map((v) => (
              <option key={v.placa} value={v.placa}>
                {v.placa}
              </option>
            ))}
          </select>
        </label>
        <label>
          Transportista
          <select value={filtros.transportista} onChange={cambiar('transportista')}>
            <option value="">Todos</option>
            {opciones.transportistas.map((t) => (
              <option key={t.usuario} value={t.usuario}>
                {t.persona}
              </option>
            ))}
          </select>
        </label>
        <label>
          Tipo de envío
          <select value={filtros.tipoEnvio} onChange={cambiar('tipoEnvio')}>
            <option value="">Todos</option>
            {TIPOS.map((t) => (
              <option key={t} value={t}>
                {t.charAt(0) + t.slice(1).toLowerCase()}
              </option>
            ))}
          </select>
        </label>
      </div>

      {estado.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando viajes y flota…
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
            <TarjetaIndicador valor={ind.viajesTerminados} titulo="Viajes terminados" />
            <TarjetaIndicador valor={ind.enRutaAhora} titulo="En ruta ahora" />
            <TarjetaIndicador valor={ind.programados} titulo="Programados" />
            <TarjetaIndicador valor={kg(ind.pesoKg)} titulo="Peso transportado" />
            <TarjetaIndicador valor={pct(ind.usoMedioCapacidadPct)} titulo="Uso medio de capacidad" />
            <TarjetaIndicador valor={pct(ind.enviosConTransbordoPct)} titulo="Envíos con transbordo" />
          </div>

          <h2>Viajes en ruta</h2>
          {datos.viajesEnRuta.length === 0 ? (
            <div className="panel estado-vacio">
              <p>No hay viajes en ruta con estos filtros.</p>
            </div>
          ) : (
            <div className="tabla-envoltorio">
              <table className="tabla-reservas">
                <caption className="solo-lectores">Viajes en ruta</caption>
                <thead>
                  <tr>
                    <th scope="col">Viaje</th>
                    <th scope="col">Ruta</th>
                    <th scope="col">Vehículo</th>
                    <th scope="col">Transportista</th>
                    <th scope="col">Salió</th>
                    <th scope="col">Llega aprox.</th>
                  </tr>
                </thead>
                <tbody>
                  {datos.viajesEnRuta.map((v) => (
                    <tr key={v.codigo}>
                      <th scope="row">{v.codigo}</th>
                      <td data-etiqueta="Ruta">
                        {v.origen.nombre} → {v.destino.nombre}
                      </td>
                      <td data-etiqueta="Vehículo">{v.placa}</td>
                      <td data-etiqueta="Transportista">{v.transportista}</td>
                      <td data-etiqueta="Salió">{horaCorta(v.salida)}</td>
                      <td data-etiqueta="Llega aprox.">{v.llegadaAprox ? `${horaCorta(v.llegadaAprox)}${v.diasDespues > 0 ? ` (+${v.diasDespues})` : ''}` : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <h2>Transportistas del {filtros.mes ? 'mes' : 'año'}</h2>
          {datos.transportistas.length === 0 ? (
            <div className="panel estado-vacio">
              <p>No hay viajes terminados en este periodo.</p>
            </div>
          ) : (
            <div className="tabla-envoltorio">
              <table className="tabla-reservas">
                <caption className="solo-lectores">Transportistas</caption>
                <thead>
                  <tr>
                    <th scope="col">Transportista</th>
                    <th scope="col">Sucursal</th>
                    <th scope="col">Viajes</th>
                    <th scope="col">Kg</th>
                  </tr>
                </thead>
                <tbody>
                  {datos.transportistas.map((t) => (
                    <tr key={`${t.transportista}-${t.sucursalCodigo}`}>
                      <th scope="row">{t.transportista}</th>
                      <td data-etiqueta="Sucursal">{t.sucursalCodigo ?? '—'}</td>
                      <td data-etiqueta="Viajes">{t.viajes}</td>
                      <td data-etiqueta="Kg">{Number(kilosSinCeros(t.pesoKg)).toLocaleString('en-US')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </section>
  )
}
