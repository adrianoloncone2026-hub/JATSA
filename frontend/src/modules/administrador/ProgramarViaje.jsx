import { useEffect, useState } from 'react'
import { obtenerOpciones, programarViaje } from '../../api/despacho.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import { kilosSinCeros } from '../../utils/formato.js'

// «Programar viaje» (diapositiva 21): elige vehículo, transportista y destino; el origen es siempre la agencia de la sesión.
export default function ProgramarViaje({ alProgramar, alCancelar }) {
  const [opciones, setOpciones] = useState({ fase: 'cargando', datos: null, mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [formulario, setFormulario] = useState({ placa: '', transportista: '', destino: '' })
  const [errores, setErrores] = useState({})
  const [enviando, setEnviando] = useState(false)
  const [aviso, setAviso] = useState('')

  useEffect(() => {
    const control = new AbortController()
    setOpciones((anterior) => ({ ...anterior, fase: 'cargando' }))
    obtenerOpciones(control.signal)
      .then((datos) => setOpciones({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setOpciones({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [intento])

  function cambiar(campo, valor) {
    setFormulario((anterior) => ({ ...anterior, [campo]: valor }))
    if (errores[campo]) setErrores((anterior) => ({ ...anterior, [campo]: '' }))
  }

  async function enviar(evento) {
    evento.preventDefault()
    if (enviando) return
    const nuevos = {}
    if (!formulario.placa) nuevos.placa = 'Elige un vehículo.'
    if (!formulario.transportista) nuevos.transportista = 'Elige un transportista.'
    if (!formulario.destino) nuevos.destino = 'Elige la agencia de destino.'
    setErrores(nuevos)
    if (Object.keys(nuevos).length > 0) {
      document.getElementById(Object.keys(nuevos)[0])?.focus()
      return
    }

    setEnviando(true)
    setAviso('')
    try {
      const viaje = await programarViaje(formulario)
      alProgramar(viaje)
    } catch (fallo) {
      if (fallo.estado === 422 && Object.keys(fallo.campos ?? {}).length > 0) setErrores(fallo.campos)
      else setAviso(fallo.message)
    } finally {
      setEnviando(false)
    }
  }

  const datos = opciones.datos

  return (
    <form className="panel programar-viaje" onSubmit={enviar} noValidate aria-labelledby="titulo-programar">
      <h2 id="titulo-programar">Programar viaje</h2>

      {opciones.fase === 'cargando' && (
        <p role="status" className="cargando">
          Cargando vehículos y transportistas…
        </p>
      )}
      {opciones.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar las opciones.</strong> {opciones.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {aviso && (
        <div className="resultado error" role="alert">
          {aviso}
        </div>
      )}

      {datos && (
        <>
          <div className="fila-triple">
            <CampoTexto id="placa" etiqueta="Vehículo" como="select" value={formulario.placa} error={errores.placa} onChange={(e) => cambiar('placa', e.target.value)}>
              <option value="">Elige…</option>
              {datos.vehiculos.map((v) => (
                <option key={v.placa} value={v.placa}>
                  {v.tipo} {v.placa} · {kilosSinCeros(v.capacidadKg)} kg{v.ocupado ? ' · en ruta' : ''}
                </option>
              ))}
            </CampoTexto>
            <CampoTexto id="transportista" etiqueta="Transportista" como="select" value={formulario.transportista} error={errores.transportista} onChange={(e) => cambiar('transportista', e.target.value)}>
              <option value="">Elige…</option>
              {datos.transportistas.map((t) => (
                <option key={t.nombreUsuario} value={t.nombreUsuario}>
                  {t.nombreCompleto} ({t.sucursal.codigo})
                </option>
              ))}
            </CampoTexto>
            <CampoTexto id="destino" etiqueta="Destino" como="select" value={formulario.destino} error={errores.destino} onChange={(e) => cambiar('destino', e.target.value)}>
              <option value="">Elige…</option>
              {datos.destinos.map((d) => (
                <option key={d.codigo} value={d.codigo}>
                  {d.nombre}
                </option>
              ))}
            </CampoTexto>
          </div>
          <div className="acciones-formulario">
            <button type="submit" className="boton boton-azul" disabled={enviando}>
              {enviando ? 'Programando…' : 'Programar'}
            </button>
            <button type="button" className="boton boton-claro" onClick={alCancelar} disabled={enviando}>
              Cancelar
            </button>
          </div>
        </>
      )}
    </form>
  )
}
