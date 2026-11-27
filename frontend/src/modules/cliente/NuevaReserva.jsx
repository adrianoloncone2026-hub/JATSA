import { useEffect, useRef, useState } from 'react'
import Mosaico from '../../components/Mosaico.jsx'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { obtenerCuenta } from '../../api/cuenta.js'
import { obtenerAgencias, obtenerRuta, obtenerTarifas } from '../../api/publico.js'
import { consultarDestinatario, crearReserva } from '../../api/reservas.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import RutaMonedas from '../../components/RutaMonedas.jsx'
import { errorDocumento, errorTelefono, sinSeparadores, soloConError } from '../../utils/validaciones.js'

const INICIAL = {
  tipoDocumento: 'DNI',
  numeroDocumento: '',
  nombreDestinatario: '',
  telefonoDestinatario: '',
  sucursalOrigen: '',
  sucursalDestino: '',
  contenido: '',
}

const ORDEN_CAMPOS = ['numeroDocumento', 'nombreDestinatario', 'telefonoDestinatario', 'sucursalOrigen', 'sucursalDestino', 'contenido']

// Diapositiva 12 del prototipo: remitente (tú), destinatario por documento, ruta, contenido y precio referencial.
export default function NuevaReserva() {
  const navegar = useNavigate()
  const formulario = useRef(null)
  const [parametros] = useSearchParams()

  const [campos, setCampos] = useState({ ...INICIAL, sucursalOrigen: parametros.get('origen') ?? '', sucursalDestino: parametros.get('destino') ?? '' })
  const [errores, setErrores] = useState({})
  const [errorGeneral, setErrorGeneral] = useState('')
  const [enviando, setEnviando] = useState(false)

  const { usuario } = useAuth()
  const [remitente, setRemitente] = useState(null)
  const [agencias, setAgencias] = useState({ fase: 'cargando', lista: [], mensaje: '' })
  const [intentoAgencias, setIntentoAgencias] = useState(0)
  const [destinatario, setDestinatario] = useState({ fase: 'inactivo' })
  const [tarifas, setTarifas] = useState({ fase: 'inactivo' })

  useEffect(() => {
    const control = new AbortController()
    obtenerCuenta(control.signal)
      .then((cuenta) => setRemitente(cuenta.persona))
      .catch(() => {})
    return () => control.abort()
  }, [])

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

  // Destinatario: al completar un documento válido se consulta su nombre (con un pequeño retardo). Cada cambio
  useEffect(() => {
    if (errorDocumento(campos.tipoDocumento, campos.numeroDocumento)) {
      setDestinatario({ fase: 'inactivo' })
      return undefined
    }
    setDestinatario({ fase: 'buscando' })
    const control = new AbortController()
    const temporizador = setTimeout(() => {
      consultarDestinatario(
        { tipoDocumento: campos.tipoDocumento, numeroDocumento: sinSeparadores(campos.numeroDocumento) },
        control.signal,
      )
        .then((respuesta) => {
          if (!respuesta.existe) setDestinatario({ fase: 'nuevo' })
          else setDestinatario({ fase: respuesta.esUsted ? 'usted' : 'existe', nombre: respuesta.nombreCompleto })
        })
        .catch((fallo) => {
          if (fallo.name === 'AbortError') return
          setDestinatario({ fase: 'error', mensaje: fallo.message })
        })
    }, 350)
    return () => {
      clearTimeout(temporizador)
      control.abort()
    }
  }, [campos.tipoDocumento, campos.numeroDocumento])

  useEffect(() => {
    const { sucursalOrigen: origen, sucursalDestino: destino } = campos
    if (!origen || !destino || origen === destino) {
      setTarifas({ fase: 'inactivo' })
      return undefined
    }
    setTarifas({ fase: 'cargando' })
    const control = new AbortController()
    obtenerTarifas({ origen, destino }, control.signal)
      .then((respuesta) => setTarifas({ fase: 'listo', tarifas: respuesta.tarifas }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setTarifas({ fase: 'error', mensaje: fallo.message })
      })
    return () => control.abort()
  }, [campos.sucursalOrigen, campos.sucursalDestino])

  const [salida, setSalida] = useState({ fase: 'inactivo' })
  useEffect(() => {
    const { sucursalOrigen: origen, sucursalDestino: destino } = campos
    if (!origen || !destino || origen === destino) {
      setSalida({ fase: 'inactivo' })
      return undefined
    }
    setSalida({ fase: 'cargando' })
    const control = new AbortController()
    obtenerRuta(`${origen}-${destino}`, control.signal)
      .then((ruta) => setSalida({ fase: 'listo', ruta }))
      .catch(() => setSalida({ fase: 'inactivo' }))
    return () => control.abort()
  }, [campos.sucursalOrigen, campos.sucursalDestino])

  const pideDatosDelDestinatario = destinatario.fase === 'nuevo' || Boolean(errores.nombreDestinatario || errores.telefonoDestinatario)
  const departamentoDe = (codigo) => agencias.lista.find((a) => a.codigo === codigo)?.departamento ?? ''
  const nombreDe = (codigo) => agencias.lista.find((a) => a.codigo === codigo)?.nombre ?? ''
  const esRuc = campos.tipoDocumento === 'RUC'

  function cambiar(evento) {
    const { name, value } = evento.target
    setCampos((anteriores) => ({ ...anteriores, [name]: value }))
    setErrores((anteriores) => (anteriores[name] ? { ...anteriores, [name]: '' } : anteriores))
  }

  function validar() {
    const contenido = campos.contenido.trim()
    return soloConError({
      numeroDocumento: errorDocumento(campos.tipoDocumento, campos.numeroDocumento) || (destinatario.fase === 'usted' ? 'El destinatario no puede ser el mismo remitente.' : ''),
      nombreDestinatario: pideDatosDelDestinatario && campos.nombreDestinatario.trim() === '' ? `Ingresa ${esRuc ? 'la razón social' : 'los nombres y apellidos'} del destinatario.` : '',
      telefonoDestinatario: pideDatosDelDestinatario ? errorTelefono(campos.telefonoDestinatario) : '',
      sucursalOrigen: campos.sucursalOrigen ? '' : 'Elige la sucursal de origen.',
      sucursalDestino: !campos.sucursalDestino ? 'Elige la sucursal de destino.' : campos.sucursalDestino === campos.sucursalOrigen ? 'El origen y el destino deben ser distintos.' : '',
      contenido: contenido === '' ? 'Declara el contenido del paquete.' : contenido.length > 200 ? 'Puede tener hasta 200 caracteres.' : '',
    })
  }

  function enfocarPrimerError(errorPorCampo) {
    const primero = ORDEN_CAMPOS.find((campo) => errorPorCampo[campo])
    if (primero) formulario.current?.querySelector(`[name="${primero}"]`)?.focus()
  }

  async function reservar(evento) {
    evento.preventDefault()
    setErrorGeneral('')
    const pendientes = validar()
    if (sinTarifas) pendientes.sucursalDestino = pendientes.sucursalDestino || 'No hay tarifas vigentes entre estas sucursales. Elige otra ruta.'
    setErrores(pendientes)
    if (Object.keys(pendientes).length > 0) {
      enfocarPrimerError(pendientes)
      return
    }

    setEnviando(true)
    try {
      const reserva = await crearReserva({
        tipoDocumento: campos.tipoDocumento,
        numeroDocumento: sinSeparadores(campos.numeroDocumento),
        nombreDestinatario: campos.nombreDestinatario.trim(),
        telefonoDestinatario: sinSeparadores(campos.telefonoDestinatario),
        sucursalOrigen: campos.sucursalOrigen,
        sucursalDestino: campos.sucursalDestino,
        contenido: campos.contenido.trim(),
      })
      navegar(`/cliente/reserva-registrada/${reserva.codigo}`, { replace: true, state: { reserva } })
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

  const sinTarifas = tarifas.fase === 'listo' && tarifas.tarifas.every((tarifa) => !tarifa.disponible)

  const opcionesSucursal = agencias.lista.map((a) => (
    <option key={a.codigo} value={a.codigo}>
      {a.nombre} ({a.codigo})
    </option>
  ))

  return (
    <section className="nueva-reserva">
      <div className="cabecera-ilustrada">
        <h1>Nueva reserva</h1>
        <Mosaico ids={[1, 4, 2, 65]} />
      </div>
      <p className="subtitulo">El precio es referencial: se confirma al pesar cada bulto en la agencia</p>

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

      <div className="nueva-reserva-contenido">
        <form ref={formulario} className="panel formulario-reserva" onSubmit={reservar} noValidate>
          <div className="campo">
            <label htmlFor="remitente">Remitente</label>
            <input id="remitente" type="text" readOnly value={remitente ? `${remitente.nombreCompleto} · ${remitente.tipoDocumento} ${remitente.numeroDocumento}` : (usuario?.nombreCompleto ?? '—')} />
          </div>

          <div className="fila-destinatario">
            <CampoTexto id="tipoDocumento" name="tipoDocumento" etiqueta="Destinatario: tipo" como="select" value={campos.tipoDocumento} onChange={cambiar}>
              <option value="DNI">DNI</option>
              <option value="RUC">RUC</option>
            </CampoTexto>
            <CampoTexto id="numeroDocumento" name="numeroDocumento" etiqueta="Número" inputMode="numeric" autoComplete="off" maxLength={14} value={campos.numeroDocumento} onChange={cambiar} error={errores.numeroDocumento} />
            <div className="estado-destinatario" role="status" aria-live="polite">
              {destinatario.fase === 'buscando' && <span className="destinatario-buscando">Buscando…</span>}
              {destinatario.fase === 'existe' && <span className="destinatario-existe">✓ {destinatario.nombre}</span>}
              {destinatario.fase === 'usted' && <span className="destinatario-aviso">Este documento es el tuyo.</span>}
              {destinatario.fase === 'nuevo' && <span className="destinatario-aviso">Destinatario nuevo: ingresa sus datos.</span>}
              {destinatario.fase === 'error' && <span className="destinatario-aviso">No se pudo consultar el documento. {destinatario.mensaje}</span>}
            </div>
          </div>

          {pideDatosDelDestinatario && (
            <div className="fila-doble">
              <CampoTexto id="nombreDestinatario" name="nombreDestinatario" etiqueta={esRuc ? 'Razón social del destinatario' : 'Nombres y apellidos del destinatario'} maxLength={150} value={campos.nombreDestinatario} onChange={cambiar} error={errores.nombreDestinatario} />
              <CampoTexto id="telefonoDestinatario" name="telefonoDestinatario" etiqueta="Teléfono del destinatario" type="tel" inputMode="numeric" maxLength={13} value={campos.telefonoDestinatario} onChange={cambiar} error={errores.telefonoDestinatario} />
            </div>
          )}

          <div className="fila-doble">
            <CampoTexto id="sucursalOrigen" name="sucursalOrigen" etiqueta="Sucursal de origen" como="select" value={campos.sucursalOrigen} onChange={cambiar} error={errores.sucursalOrigen} disabled={agencias.fase !== 'listo'}>
              <option value="">{agencias.fase === 'cargando' ? 'Cargando…' : 'Elige una sucursal'}</option>
              {opcionesSucursal}
            </CampoTexto>
            <CampoTexto id="sucursalDestino" name="sucursalDestino" etiqueta="Sucursal de destino" como="select" value={campos.sucursalDestino} onChange={cambiar} error={errores.sucursalDestino} disabled={agencias.fase !== 'listo'}>
              <option value="">{agencias.fase === 'cargando' ? 'Cargando…' : 'Elige una sucursal'}</option>
              {opcionesSucursal}
            </CampoTexto>
          </div>

          <CampoTexto id="contenido" name="contenido" etiqueta="Contenido declarado" maxLength={200} value={campos.contenido} onChange={cambiar} error={errores.contenido} />

          <div className="campo campo-canal">
            <label htmlFor="canal">Canal</label>
            <input id="canal" type="text" readOnly value="WEB" />
          </div>

          <div className="acciones-formulario">
            <button type="submit" className="boton boton-azul" disabled={enviando || agencias.fase !== 'listo' || sinTarifas} aria-describedby={sinTarifas ? 'aviso-sin-tarifas' : undefined}>
              {enviando ? 'Reservando…' : 'Reservar'}
            </button>
            <Link to="/cliente" className="boton boton-claro">
              Cancelar
            </Link>
          </div>
        </form>

        <aside className="panel-precio" aria-label="Precio referencial">
          <RutaMonedas
            origen={departamentoDe(campos.sucursalOrigen)}
            destino={departamentoDe(campos.sucursalDestino)}
            nombreOrigen={nombreDe(campos.sucursalOrigen)}
            nombreDestino={nombreDe(campos.sucursalDestino)}
            tamano={80}
          />
          <h2>Precio referencial</h2>

          {tarifas.fase === 'inactivo' && <p className="ayuda">Elige el origen y el destino para ver los precios por tipo de envío.</p>}
          {tarifas.fase === 'cargando' && (
            <p role="status" className="ayuda">
              Consultando precios…
            </p>
          )}
          {tarifas.fase === 'error' && (
            <p role="alert" className="mensaje-error">
              No se pudieron consultar los precios. {tarifas.mensaje}
            </p>
          )}
          {tarifas.fase === 'listo' && (
            <>
              <table className="tabla-tarifas tabla-compacta">
                <caption className="solo-lectores">Precio referencial por tipo de envío</caption>
                <thead>
                  <tr>
                    <th scope="col">Tipo</th>
                    <th scope="col">Hasta</th>
                    <th scope="col">Precio</th>
                  </tr>
                </thead>
                <tbody>
                  {tarifas.tarifas.map((tarifa) => (
                    <tr key={tarifa.tipo}>
                      <th scope="row">{tarifa.tipo}</th>
                      <td>{tarifa.pesoMaximoKg} kg</td>
                      <td>{tarifa.disponible ? `S/ ${tarifa.precioPorBulto}` : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {sinTarifas && (
                <p id="aviso-sin-tarifas" role="alert" className="mensaje-aviso">
                  No hay tarifas vigentes entre estas sucursales, así que no se puede reservar esta ruta. Elige otro origen o destino.
                </p>
              )}
            </>
          )}

          {salida.fase === 'listo' && <ProximaSalida ruta={salida.ruta} />}

          <Link to={campos.sucursalOrigen && campos.sucursalDestino ? `/salidas/${campos.sucursalOrigen}-${campos.sucursalDestino}` : '/salidas'} className="boton boton-claro">
            Ver horarios de la ruta
          </Link>
        </aside>
      </div>
    </section>
  )
}

function ProximaSalida({ ruta }) {
  if (ruta.horarios.length === 0) return <p className="ayuda">Salida frecuente: todavía no hay salidas registradas en esta ruta (sin referencia de horario).</p>
  const ahora = new Date().getHours()
  const hora = ruta.horarios.find((h) => Number(h.slice(0, 2)) > ahora) ?? ruta.horarios[0]
  const llegada = `${String((Number(hora.slice(0, 2)) + ruta.horas) % 24).padStart(2, '0')}:00`
  return (
    <p className="proxima-salida">
      <strong>Salida frecuente (referencia histórica)</strong>
      <span>
        {hora} · llegaría aprox. {llegada} ({ruta.horas} h). Confirma la salida en la agencia.
      </span>
    </p>
  )
}
