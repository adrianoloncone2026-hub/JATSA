import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { listarSalidas, obtenerAgencias } from '../../api/publico.js'
import ilustracion from '../../assets/ilustracion-sur.png'
import Mosaico, { ImagenAzul } from '../../components/Mosaico.jsx'
import Carrusel from '../../components/Carrusel.jsx'
import Moneda from '../../components/Moneda.jsx'
import { direccionCompleta, formatoTelefono } from '../../utils/formato.js'
import { fotosDeRegion } from '../../utils/regiones.js'

// Diapositiva 6 del prototipo: agencias activas, filtro por departamento y ficha de la agencia elegida.
export default function Agencias() {
  const [estado, setEstado] = useState({ fase: 'cargando', agencias: [], mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [departamento, setDepartamento] = useState('')
  const [parametros, setParametros] = useSearchParams()

  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    obtenerAgencias(control.signal)
      .then((agencias) => setEstado({ fase: 'listo', agencias, mensaje: '' }))
      .catch((error) => {
        if (error.name === 'AbortError') return
        setEstado({ fase: 'error', agencias: [], mensaje: error.message })
      })
    return () => control.abort()
  }, [intento])

  const departamentos = useMemo(
    () => [...new Set(estado.agencias.map((a) => a.departamento))].sort((a, b) => a.localeCompare(b, 'es')),
    [estado.agencias],
  )
  const visibles = departamento ? estado.agencias.filter((a) => a.departamento === departamento) : estado.agencias
  const pedida = parametros.get('agencia')
  const elegida = visibles.find((a) => a.codigo === pedida) ?? visibles[0] ?? null

  const fotosElegida = elegida ? fotosDeRegion(elegida.departamento) : []
  const codigoElegido = elegida?.codigo ?? null
  useEffect(() => {
    if (codigoElegido !== null && codigoElegido !== pedida) {
      setParametros({ agencia: codigoElegido }, { replace: true })
    }
  }, [codigoElegido, pedida, setParametros])

  const [salidas, setSalidas] = useState({ fase: 'inactivo', lista: [] })
  useEffect(() => {
    if (!codigoElegido) {
      setSalidas({ fase: 'inactivo', lista: [] })
      return undefined
    }
    const control = new AbortController()
    setSalidas({ fase: 'cargando', lista: [] })
    listarSalidas({ origen: codigoElegido }, control.signal)
      .then((lista) => setSalidas({ fase: 'listo', lista }))
      .catch((fallo) => {
        if (fallo.name !== 'AbortError') setSalidas({ fase: 'error', lista: [] })
      })
    return () => control.abort()
  }, [codigoElegido])

  function elegir(codigo) {
    setParametros({ agencia: codigo }, { replace: true })
  }

  function alternarDepartamento(nombre) {
    setDepartamento((actual) => (actual === nombre ? '' : nombre))
  }

  return (
    <div className="agencias">
      <h1 className="solo-lectores">Agencias</h1>

      <section className="panel filtro-agencias" aria-label="Filtro por departamento">
        <div className="campo">
          <label htmlFor="filtro-departamento">Buscar por departamento</label>
          <select id="filtro-departamento" value={departamento} onChange={(e) => setDepartamento(e.target.value)}>
            <option value="">Todos</option>
            {departamentos.map((nombre) => (
              <option key={nombre} value={nombre}>
                {nombre}
              </option>
            ))}
          </select>
        </div>
        <div className="monedas-filtro">
          {departamentos.map((nombre) => (
            <button
              key={nombre}
              type="button"
              className="moneda-boton"
              aria-pressed={departamento === nombre}
              onClick={() => alternarDepartamento(nombre)}
            >
              <Moneda departamento={nombre} tamano={56} />
              <span>{nombre}</span>
            </button>
          ))}
        </div>
      </section>

      {estado.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando agencias…
        </p>
      )}

      {estado.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar las agencias.</strong> {estado.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}

      {estado.fase === 'listo' && estado.agencias.length === 0 && (
        <p className="panel">Por ahora no hay agencias disponibles.</p>
      )}

      {estado.fase === 'listo' && estado.agencias.length > 0 && (
        <div className="agencias-contenido">
          <ul className="lista-agencias" aria-label="Agencias">
            {visibles.map((agencia) => {
              const activa = elegida?.codigo === agencia.codigo
              return (
                <li key={agencia.codigo} className={activa ? 'agencia-item activa' : 'agencia-item'}>
                  <Moneda departamento={agencia.departamento} />
                  <div className="agencia-item-texto">
                    <h2>{agencia.nombre}</h2>
                    <p>
                      {agencia.direccion}, {agencia.distrito}
                    </p>
                  </div>
                  <button
                    type="button"
                    className={activa ? 'boton boton-azul' : 'boton boton-claro'}
                    aria-current={activa ? 'true' : undefined}
                    aria-label={`Ver ${agencia.nombre}`}
                    onClick={() => elegir(agencia.codigo)}
                  >
                    Ver
                  </button>
                </li>
              )
            })}
          </ul>

          {elegida && (
            <article className="panel ficha-agencia" aria-label={`Ficha de ${elegida.nombre}`}>
              <div className="banda-azul">
                <ImagenAzul id={12} />
                <h2>
                  {elegida.nombre} · {elegida.codigo}
                </h2>
                <Mosaico ids={[11, 65, 64]} className="banda-mosaico" />
              </div>
              <div className="ficha-cuerpo">
                {fotosElegida.length > 0 ? (
                  <div className="ficha-foto">
                    <Carrusel fotos={fotosElegida} etiqueta={`Fotografías de ${elegida.departamento}`} />
                    <p className="foto-lugar-region">
                      <strong>{elegida.departamento}</strong> · {elegida.provincia}
                    </p>
                  </div>
                ) : (
                  <img className="ficha-ilustracion" src={ilustracion} alt="" />
                )}
                <dl className="ficha-datos">
                  <dt>Dirección</dt>
                  <dd>{direccionCompleta(elegida)}</dd>
                  <dt>Teléfonos</dt>
                  <dd>
                    {elegida.telefonos.length > 0 ? (
                      <ul className="lista-simple">
                        {elegida.telefonos.map((numero) => (
                          <li key={numero}>{formatoTelefono(numero)}</li>
                        ))}
                      </ul>
                    ) : (
                      'Sin teléfono registrado'
                    )}
                  </dd>
                </dl>
              </div>
              <section className="salidas-desde-aqui" aria-label="Salidas desde aquí">
                <h3>Salidas desde aquí</h3>
                {salidas.fase === 'cargando' && <p className="ayuda">Cargando las salidas…</p>}
                {salidas.fase === 'error' && <p className="ayuda">No se pudieron cargar las salidas de esta agencia.</p>}
                {salidas.fase === 'listo' && salidas.lista.length === 0 && <p className="ayuda">Esta agencia todavía no tiene rutas con salidas.</p>}
                {salidas.fase === 'listo' && salidas.lista.length > 0 && (
                  <table className="tabla-compacta">
                    <caption className="solo-lectores">Salidas desde {elegida.nombre}</caption>
                    <thead>
                      <tr>
                        <th scope="col">Hacia</th>
                        <th scope="col">Salidas frecuentes</th>
                        <th scope="col">Tiempo</th>
                        <th scope="col">Acción</th>
                      </tr>
                    </thead>
                    <tbody>
                      {salidas.lista.map((s) => (
                        <tr key={s.id}>
                          <th scope="row">{s.destino.nombre}</th>
                          <td>{s.horarios.length > 0 ? s.horarios.join(' · ') : s.programado ? 'viaje programado, sin historial' : 'sin historial de salidas'}</td>
                          <td>{s.horas} h</td>
                          <td>
                            <Link to={`/salidas/${s.id}`} className="boton boton-claro" aria-label={`Ver la salida hacia ${s.destino.nombre}`}>
                              Ver salida
                            </Link>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </section>
              <Link to={`/salidas?origen=${elegida.codigo}`} className="boton boton-marino">
                Ver todas las salidas
              </Link>
            </article>
          )}
        </div>
      )}
    </div>
  )
}
