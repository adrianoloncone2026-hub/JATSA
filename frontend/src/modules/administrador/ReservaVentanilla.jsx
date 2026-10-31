import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { obtenerAgencias, obtenerTarifas } from '../../api/publico.js'
import { crearReservaVentanilla } from '../../api/ventanilla.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import { errorDocumentoPersonal, errorTelefono, sinSeparadores, soloConError } from '../../utils/validaciones.js'
import CampoPersona from './CampoPersona.jsx'

const INICIAL = {
  remitenteTipoDocumento: 'DNI',
  remitenteNumeroDocumento: '',
  remitenteNombre: '',
  remitenteTelefono: '',
  destinatarioTipoDocumento: 'DNI',
  destinatarioNumeroDocumento: '',
  destinatarioNombre: '',
  destinatarioTelefono: '',
  sucursalDestino: '',
  contenido: '',
}

const ORDEN_CAMPOS = [
  'remitenteNumeroDocumento',
  'remitenteNombre',
  'remitenteTelefono',
  'destinatarioNumeroDocumento',
  'destinatarioNombre',
  'destinatarioTelefono',
  'sucursalDestino',
  'contenido',
]

// «Cliente sin reserva» (diapositiva 18): el cliente llega sin reserva y el personal la crea con canal VENTANILLA.
// El origen es siempre la agencia del personal; después se pasa a «Formalizar envío».
export default function ReservaVentanilla() {
  const navegar = useNavigate()
  const { usuario } = useAuth()
  const formulario = useRef(null)
  const origen = usuario?.sucursal

  const [campos, setCampos] = useState(INICIAL)
  const [errores, setErrores] = useState({})
  const [errorGeneral, setErrorGeneral] = useState('')
  const [enviando, setEnviando] = useState(false)
  const [agencias, setAgencias] = useState({ fase: 'cargando', lista: [], mensaje: '' })
  const [intentoAgencias, setIntentoAgencias] = useState(0)
  const [tarifas, setTarifas] = useState({ fase: 'inactivo' }) // inactivo | cargando | listo | error
  const [esNueva, setEsNueva] = useState({ remitente: false, destinatario: false })

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
  }, [intentoAgencias])

  // Tarifas de la ruta (agencia de la sesión → destino): sin ninguna tarifa vigente no se puede reservar.
  useEffect(() => {
    if (!origen || !campos.sucursalDestino || campos.sucursalDestino === origen.codigo) {
      setTarifas({ fase: 'inactivo' })
      return undefined
    }
    setTarifas({ fase: 'cargando' })
    const control = new AbortController()
    obtenerTarifas({ origen: origen.codigo, destino: campos.sucursalDestino }, control.signal)
      .then((respuesta) => setTarifas({ fase: 'listo', tarifas: respuesta.tarifas }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setTarifas({ fase: 'error', mensaje: fallo.message })
      })
    return () => control.abort()
  }, [origen, campos.sucursalDestino])

  const sinTarifas = tarifas.fase === 'listo' && tarifas.tarifas.every((tarifa) => !tarifa.disponible)

  const cambiar = useCallback((evento) => {
    const { name, value } = evento.target
    setCampos((anteriores) => ({ ...anteriores, [name]: value }))
    setErrores((anteriores) => (anteriores[name] ? { ...anteriores, [name]: '' } : anteriores))
  }, [])

  const alCambiarEstado = useCallback((rol, fase) => {
    setEsNueva((anterior) => (anterior[rol] === (fase === 'nuevo') ? anterior : { ...anterior, [rol]: fase === 'nuevo' }))
  }, [])

  function validar() {
    const contenido = campos.contenido.trim()
    const errorDe = (rol) => ({
      [`${rol}NumeroDocumento`]: errorDocumentoPersonal(campos[`${rol}TipoDocumento`], campos[`${rol}NumeroDocumento`]),
      [`${rol}Nombre`]: esNueva[rol] && campos[`${rol}Nombre`].trim() === '' ? 'Ingresa el nombre.' : '',
      [`${rol}Telefono`]: esNueva[rol] ? errorTelefono(campos[`${rol}Telefono`]) : '',
    })
    const mismo =
      campos.remitenteTipoDocumento === campos.destinatarioTipoDocumento &&
      sinSeparadores(campos.remitenteNumeroDocumento).toUpperCase() === sinSeparadores(campos.destinatarioNumeroDocumento).toUpperCase() &&
      campos.remitenteNumeroDocumento.trim() !== ''
    return soloConError({
      ...errorDe('remitente'),
      ...errorDe('destinatario'),
      ...(mismo ? { destinatarioNumeroDocumento: 'El destinatario no puede ser el mismo remitente.' } : {}),
      sucursalDestino: !campos.sucursalDestino
        ? 'Elige la sucursal de destino.'
        : sinTarifas
          ? 'No hay tarifas vigentes entre estas sucursales. Elige otra ruta.'
          : '',
      contenido: contenido === '' ? 'Declara el contenido del paquete.' : contenido.length > 200 ? 'Puede tener hasta 200 caracteres.' : '',
    })
  }

  function enfocarPrimerError(errorPorCampo) {
    const primero = ORDEN_CAMPOS.find((campo) => errorPorCampo[campo])
    if (primero) formulario.current?.querySelector(`[name="${primero}"]`)?.focus()
  }

  async function crear(evento) {
    evento.preventDefault()
    setErrorGeneral('')
    const pendientes = validar()
    setErrores(pendientes)
    if (Object.keys(pendientes).length > 0) {
      enfocarPrimerError(pendientes)
      return
    }

    setEnviando(true)
    try {
      const reserva = await crearReservaVentanilla({
        remitenteTipoDocumento: campos.remitenteTipoDocumento,
        remitenteNumeroDocumento: sinSeparadores(campos.remitenteNumeroDocumento),
        remitenteNombre: campos.remitenteNombre.trim(),
        remitenteTelefono: sinSeparadores(campos.remitenteTelefono),
        destinatarioTipoDocumento: campos.destinatarioTipoDocumento,
        destinatarioNumeroDocumento: sinSeparadores(campos.destinatarioNumeroDocumento),
        destinatarioNombre: campos.destinatarioNombre.trim(),
        destinatarioTelefono: sinSeparadores(campos.destinatarioTelefono),
        sucursalDestino: campos.sucursalDestino,
        contenido: campos.contenido.trim(),
      })
      navegar(`/administrador/formalizar-envio/${reserva.codigo}`, { replace: true })
    } catch (fallo) {
      setEnviando(false)
      const delServidor = fallo.campos ?? {}
      if (Object.keys(delServidor).length > 0) {
        setErrores(delServidor)
        enfocarPrimerError(delServidor)
      } else {
        setErrorGeneral(fallo.message)
      }
    }
  }

  const destinos = agencias.lista.filter((a) => a.codigo !== origen?.codigo)

  return (
    <section className="reserva-ventanilla">
      <h1>Cliente sin reserva</h1>
      <p className="subtitulo">Se crea la reserva con canal VENTANILLA y se pasa a formalizar el envío</p>

      {errorGeneral && (
        <div className="resultado error" role="alert">
          {errorGeneral}
        </div>
      )}
      {agencias.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>No se pudieron cargar las sucursales.</strong> {agencias.mensaje}{' '}
          <button type="button" className="boton boton-claro" onClick={() => setIntentoAgencias((n) => n + 1)}>
            Reintentar
          </button>
        </div>
      )}

      <form ref={formulario} className="panel formulario-reserva" onSubmit={crear} noValidate>
        <CampoPersona rol="remitente" etiqueta="Remitente" valores={campos} errores={errores} onCambio={cambiar} onEstado={alCambiarEstado} />
        <CampoPersona rol="destinatario" etiqueta="Destinatario" valores={campos} errores={errores} onCambio={cambiar} onEstado={alCambiarEstado} />

        <div className="fila-doble">
          <div className="campo">
            <label htmlFor="sucursalOrigen">Sucursal de origen</label>
            <input id="sucursalOrigen" type="text" readOnly value={origen ? `${origen.nombre} (${origen.codigo})` : '—'} />
          </div>
          <CampoTexto id="sucursalDestino" name="sucursalDestino" etiqueta="Sucursal de destino" como="select" value={campos.sucursalDestino} onChange={cambiar} error={errores.sucursalDestino} disabled={agencias.fase !== 'listo'}>
            <option value="">{agencias.fase === 'cargando' ? 'Cargando…' : 'Elige una sucursal'}</option>
            {destinos.map((a) => (
              <option key={a.codigo} value={a.codigo}>
                {a.nombre} ({a.codigo})
              </option>
            ))}
          </CampoTexto>
        </div>

        {sinTarifas && (
          <p id="aviso-sin-tarifas" role="alert" className="mensaje-aviso">
            No hay tarifas vigentes entre estas sucursales, así que no se puede reservar esta ruta. Elige otro destino.
          </p>
        )}

        <CampoTexto id="contenido" name="contenido" etiqueta="Contenido declarado" maxLength={200} value={campos.contenido} onChange={cambiar} error={errores.contenido} />

        <div className="campo campo-canal">
          <label htmlFor="canal">Canal</label>
          <input id="canal" type="text" readOnly value="VENTANILLA" />
        </div>

        <div className="acciones-formulario">
          <button type="submit" className="boton boton-azul" disabled={enviando || agencias.fase !== 'listo' || sinTarifas} aria-describedby={sinTarifas ? 'aviso-sin-tarifas' : undefined}>
            {enviando ? 'Creando…' : 'Crear reserva y formalizar'}
          </button>
          <Link to="/administrador" className="boton boton-claro">
            Cancelar
          </Link>
        </div>
      </form>
    </section>
  )
}
