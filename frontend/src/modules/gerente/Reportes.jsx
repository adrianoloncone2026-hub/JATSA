import { useEffect, useState } from 'react'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { ROLES } from '../../auth/roles.js'
import { descargarExcel, descargarPowerBi } from '../../api/reportes.js'
import { listarSucursales } from '../../api/catalogos.js'
import Mosaico from '../../components/Mosaico.jsx'

const EXCELES = [
  { tipo: 'gerencia', titulo: 'Excel de Gestión Gerencial', powerBi: 'Gestión Gerencial', detalle: 'Hojas EnviosResumen, Pagos y Sucursales. Power BI: ZIP con reporte .pbix, Excel y manual PDF.', ids: [8, 22, 6] },
  { tipo: 'logistica', titulo: 'Excel de Transporte y Logística', powerBi: 'Transporte y Logística', detalle: 'Hojas Viajes, EnvioViaje, EnviosCarga y Sucursales. Power BI: ZIP con reporte .pbix, Excel y manual PDF.', ids: [23, 2, 13] },
]

// Reportes y plantillas (módulo 12): dos descargas .xlsx. El ámbito lo decide el backend: el Gerente exporta todo (o una sucursal) y el Administrador solo la suya.
export default function Reportes() {
  const { usuario } = useAuth()
  const esGerente = usuario?.rol === ROLES.GERENTE
  const [sucursales, setSucursales] = useState([])
  const [sucursal, setSucursal] = useState('')
  const [descargando, setDescargando] = useState('')
  const [aviso, setAviso] = useState({ tipo: '', texto: '' })

  useEffect(() => {
    if (!esGerente) return undefined
    const control = new AbortController()
    listarSucursales(control.signal)
      .then((lista) => setSucursales(Array.isArray(lista) ? lista : (lista?.sucursales ?? [])))
      .catch(() => setSucursales([]))
    return () => control.abort()
  }, [esGerente])

  async function bajar(excel, powerBi = false) {
    const clave = powerBi ? `${excel.tipo}-powerbi` : excel.tipo
    setDescargando(clave)
    setAviso({ tipo: '', texto: '' })
    try {
      const { blob, nombre } = await (powerBi ? descargarPowerBi : descargarExcel)(excel.tipo, esGerente ? { sucursal } : {})
      const url = URL.createObjectURL(blob)
      const enlace = document.createElement('a')
      enlace.href = url
      enlace.download = nombre
      document.body.appendChild(enlace)
      enlace.click()
      enlace.remove()
      URL.revokeObjectURL(url)
      setAviso({ tipo: 'ok', texto: `Se descargó ${nombre}.` })
    } catch (error) {
      setAviso({ tipo: 'error', texto: error.campos?.sucursal ?? error.message })
    } finally {
      setDescargando('')
    }
  }

  return (
    <section className="reportes">
      <div className="cabecera-ilustrada">
        <div>
          <h1>Reportes y plantillas</h1>
          <p className="ayuda">
            {esGerente
              ? 'Descarga el historial completo, o elige una sucursal para acotarlo.'
              : `Descarga la información de tu sucursal${usuario?.sucursal?.nombre ? ` (${usuario.sucursal.nombre})` : ''}.`}
          </p>
        </div>
        <Mosaico ids={[22, 17, 8, 56]} />
      </div>

      {esGerente && (
        <div className="panel campo">
          <label htmlFor="sucursalExcel">Sucursal</label>
          <select id="sucursalExcel" value={sucursal} onChange={(e) => setSucursal(e.target.value)} disabled={descargando !== ''}>
            <option value="">Todas</option>
            {sucursales.map((s) => (
              <option key={s.codigoSucursal ?? s.codigo} value={s.codigoSucursal ?? s.codigo}>
                {s.nombre}
              </option>
            ))}
          </select>
        </div>
      )}

      {aviso.texto && (
        <div className={`resultado ${aviso.tipo === 'ok' ? 'ok' : 'error'}`} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
          {aviso.texto}
        </div>
      )}

      <div className="datos-iconos">
        {EXCELES.map((excel) => (
          <article key={excel.tipo} className="panel">
            <Mosaico ids={excel.ids} />
            <h2>{excel.titulo}</h2>
            <p className="ayuda">{excel.detalle}</p>
            <div className="acciones-descarga">
            <button type="button" className="boton boton-verde" aria-label={`Descargar ${excel.titulo}`} onClick={() => bajar(excel)} disabled={descargando !== ''}>
              {descargando === excel.tipo ? 'Generando…' : 'Descargar Excel'}
            </button>
            <button type="button" className="boton boton-powerbi" aria-label={`Descargar paquete Power BI: ${excel.powerBi}`} onClick={() => bajar(excel, true)} disabled={descargando !== ''}>
              {descargando === `${excel.tipo}-powerbi` ? 'Generando…' : 'Descargar Power BI'}
            </button>
            </div>
          </article>
        ))}
      </div>
    </section>
  )
}