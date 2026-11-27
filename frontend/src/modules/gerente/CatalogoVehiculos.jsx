import { useCallback, useEffect, useState } from 'react'
import { actualizarVehiculo, cambiarEstadoVehiculo, crearVehiculo, listarVehiculos } from '../../api/catalogos.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import InsigniaEstado from '../../components/InsigniaEstado.jsx'

const TIPOS = ['CAMION', 'FURGON', 'CAMIONETA', 'MOTO']
const VACIO = { placa: '', tipoVehiculo: 'FURGON', capacidadKg: '' }

// Pestaña «Vehículos» de Catálogos (diapositiva 29). La placa no cambia; un vehículo no se borra: se desactiva (si no tiene viajes
export default function CatalogoVehiculos() {
  const [estado, setEstado] = useState({ fase: 'cargando', vehiculos: [], mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [formulario, setFormulario] = useState(null)
  const [errores, setErrores] = useState({})
  const [enviando, setEnviando] = useState(false)
  const [aviso, setAviso] = useState(null)
  const [confirmando, setConfirmando] = useState('')

  const recargar = useCallback(() => setIntento((n) => n + 1), [])
  useEffect(() => {
    const control = new AbortController()
    setEstado((anterior) => ({ ...anterior, fase: 'cargando' }))
    listarVehiculos(control.signal)
      .then((datos) => setEstado({ fase: 'listo', vehiculos: datos.vehiculos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name !== 'AbortError') setEstado({ fase: 'error', vehiculos: [], mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  const cambiar = (campo, valor) => setFormulario((f) => ({ ...f, campos: { ...f.campos, [campo]: valor } }))

  async function guardar(evento) {
    evento.preventDefault()
    const { editando, campos } = formulario
    setEnviando(true)
    setErrores({})
    try {
      const guardado = editando ? await actualizarVehiculo(editando, campos) : await crearVehiculo(campos)
      setAviso({ tipo: 'ok', texto: editando ? `Vehículo ${guardado.placa} actualizado.` : `Vehículo ${guardado.placa} creado.` })
      setFormulario(null)
      recargar()
    } catch (fallo) {
      if (Object.keys(fallo.campos ?? {}).length > 0) setErrores(fallo.campos)
      else setAviso({ tipo: 'error', texto: fallo.message })
    } finally {
      setEnviando(false)
    }
  }

  async function alternar(v) {
    setConfirmando('')
    setAviso(null)
    try {
      const resultado = await cambiarEstadoVehiculo(v.placa, !v.activo)
      setAviso({ tipo: 'ok', texto: `Vehículo ${resultado.placa} ${resultado.activo ? 'activado' : 'desactivado'}.` })
      recargar()
    } catch (fallo) {
      setAviso({ tipo: 'error', texto: fallo.message })
    }
  }

  return (
    <section aria-label="Vehículos">
      <div className="barra-historial">
        <h2>Vehículos</h2>
        <button
          type="button"
          className="boton boton-azul"
          onClick={() => {
            setAviso(null)
            setErrores({})
            setFormulario({ editando: null, campos: VACIO })
          }}
        >
          Nuevo vehículo
        </button>
      </div>
      {aviso && (
        <div className={aviso.tipo === 'ok' ? 'resultado ok' : 'resultado error'} role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
          {aviso.texto}
        </div>
      )}

      {formulario && (
        <form className="panel formulario-catalogo" onSubmit={guardar} noValidate aria-label={formulario.editando ? 'Editar vehículo' : 'Nuevo vehículo'}>
          <h3>{formulario.editando ? `Editar ${formulario.editando}` : 'Nuevo vehículo'}</h3>
          <div className="rejilla-formulario">
            <CampoTexto id="veh-placa" etiqueta="Placa (ABC-123)" value={formulario.campos.placa} disabled={Boolean(formulario.editando)} maxLength={7} placeholder="B7K-210" error={errores.placa} onChange={(e) => cambiar('placa', e.target.value)} />
            <CampoTexto id="veh-tipo" etiqueta="Tipo" como="select" value={formulario.campos.tipoVehiculo} error={errores.tipoVehiculo} onChange={(e) => cambiar('tipoVehiculo', e.target.value)}>
              {TIPOS.map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </CampoTexto>
            <CampoTexto id="veh-capacidad" etiqueta="Capacidad (kg)" inputMode="decimal" value={formulario.campos.capacidadKg} error={errores.capacidadKg} onChange={(e) => cambiar('capacidadKg', e.target.value)} />
          </div>
          <div className="acciones-formulario">
            <button type="submit" className="boton boton-azul" disabled={enviando}>
              {enviando ? 'Guardando…' : 'Guardar'}
            </button>
            <button type="button" className="boton boton-claro" onClick={() => setFormulario(null)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {estado.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando los vehículos…
        </p>
      )}
      {estado.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar los vehículos.</strong> {estado.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={recargar}>
            Reintentar
          </button>
        </div>
      )}
      {estado.fase === 'listo' && estado.vehiculos.length === 0 && (
        <div className="panel estado-vacio">
          <p>Todavía no hay vehículos. Registra el primero.</p>
        </div>
      )}
      {estado.fase === 'listo' && estado.vehiculos.length > 0 && (
        <div className="tabla-envoltorio">
          <table className="tabla-reservas tabla-catalogo">
            <caption className="solo-lectores">Vehículos</caption>
            <thead>
              <tr>
                <th scope="col">Placa</th>
                <th scope="col">Tipo</th>
                <th scope="col">Capacidad</th>
                <th scope="col">Viajes abiertos</th>
                <th scope="col">Estado</th>
                <th scope="col">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {estado.vehiculos.map((v) => (
                <tr key={v.placa}>
                  <th scope="row">{v.placa}</th>
                  <td data-etiqueta="Tipo">{v.tipoVehiculo}</td>
                  <td data-etiqueta="Capacidad">{v.capacidadKg} kg</td>
                  <td data-etiqueta="Viajes abiertos">{v.viajesAbiertos}</td>
                  <td data-etiqueta="Estado">
                    <InsigniaEstado estado={v.activo ? 'ACTIVA' : 'INACTIVA'} />
                  </td>
                  <td data-etiqueta="Acciones" className="celda-accion">
                    <button
                      type="button"
                      className="boton boton-claro"
                      aria-label={`Editar ${v.placa}`}
                      onClick={() => {
                        setAviso(null)
                        setErrores({})
                        setFormulario({ editando: v.placa, campos: { placa: v.placa, tipoVehiculo: v.tipoVehiculo, capacidadKg: v.capacidadKg } })
                      }}
                    >
                      Editar
                    </button>
                    {confirmando === v.placa ? (
                      <>
                        <button type="button" className="boton boton-peligro" onClick={() => alternar(v)}>
                          Confirmar
                        </button>
                        <button type="button" className="boton boton-claro" onClick={() => setConfirmando('')}>
                          No
                        </button>
                      </>
                    ) : (
                      <button type="button" className="boton boton-claro" aria-label={`${v.activo ? 'Desactivar' : 'Activar'} ${v.placa}`} onClick={() => (v.activo ? setConfirmando(v.placa) : alternar(v))}>
                        {v.activo ? 'Desactivar' : 'Activar'}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}