import { useEffect, useRef, useState } from 'react'
import Mosaico from '../../components/Mosaico.jsx'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { buscarReservaVentanilla, cotizarBultos, formalizarEnvio } from '../../api/ventanilla.js'
import { obtenerTarifas } from '../../api/publico.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import RangosPeso from '../../components/RangosPeso.jsx'
import RutaMonedas from '../../components/RutaMonedas.jsx'
import { fechaHoraCorta } from '../../utils/formato.js'

const PESO_VALIDO = /^\d{1,6}([.,]\d{1,2})?$/

// Diapositiva 19 del prototipo: tipo de entrega, bultos con su tipo (por peso) y costo (por ruta), importe y guardar.
export default function FormalizarEnvio() {
  const { codigo } = useParams()
  const navegar = useNavigate()
  const siguienteId = useRef(1)

  const [reserva, setReserva] = useState({ fase: 'cargando', datos: null, mensaje: '' })
  const [intento, setIntento] = useState(0)
  const [tipoEntrega, setTipoEntrega] = useState('AGENCIA')
  const [direccion, setDireccion] = useState('')
  const [bultos, setBultos] = useState([])
  const [borrador, setBorrador] = useState({ descripcion: '', pesoKg: '' })
  const [cotizacion, setCotizacion] = useState({ fase: 'inactivo', datos: null })
  const [previa, setPrevia] = useState({ fase: 'inactivo', fila: null })
  const [errorBorrador, setErrorBorrador] = useState('')
  const [erroresServidor, setErroresServidor] = useState({})
  const [errorGeneral, setErrorGeneral] = useState('')
  const [guardando, setGuardando] = useState(false)

  useEffect(() => {
    const control = new AbortController()
    setReserva((anterior) => ({ ...anterior, fase: 'cargando' }))
    buscarReservaVentanilla(codigo, control.signal)
      .then((datos) => setReserva({ fase: 'listo', datos, mensaje: '' }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setReserva({ fase: fallo.estado === 404 ? 'no_encontrada' : 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [codigo, intento])

  const formalizable = reserva.fase === 'listo' && reserva.datos.estado === 'PENDIENTE' && !reserva.datos.plazoCumplido

  const [tarifasRuta, setTarifasRuta] = useState([])
  const origenRuta = reserva.fase === 'listo' ? reserva.datos.origen.codigo : ''
  const destinoRuta = reserva.fase === 'listo' ? reserva.datos.destino.codigo : ''
  useEffect(() => {
    if (!origenRuta || !destinoRuta) return undefined
    const control = new AbortController()
    obtenerTarifas({ origen: origenRuta, destino: destinoRuta }, control.signal)
      .then((datos) => setTarifasRuta(datos.tarifas))
      .catch(() => {})
    return () => control.abort()
  }, [origenRuta, destinoRuta])

  // Cotización de los bultos agregados. Cada cambio cancela la anterior: una respuesta lenta nunca pisa a la actual.
  useEffect(() => {
    if (!formalizable) return undefined
    if (bultos.length === 0) {
      setCotizacion({ fase: 'listo', datos: null })
      return undefined
    }
    setCotizacion((anterior) => ({ ...anterior, fase: 'cargando' }))
    const control = new AbortController()
    cotizarBultos({ codigoReserva: codigo, bultos: bultos.map(({ descripcion, pesoKg }) => ({ descripcion, pesoKg })) }, control.signal)
      .then((datos) => setCotizacion({ fase: 'listo', datos }))
      .catch((fallo) => {
        if (fallo.name === 'AbortError') return
        setCotizacion({ fase: 'error', datos: null, mensaje: fallo.message })
      })
    return () => control.abort()
  }, [formalizable, codigo, bultos])

  useEffect(() => {
    const peso = borrador.pesoKg.trim()
    if (!formalizable || peso === '') {
      setPrevia({ fase: 'inactivo', fila: null })
      return undefined
    }
    if (!PESO_VALIDO.test(peso)) {
      setPrevia({ fase: 'listo', fila: { error: 'Ingresa el peso en kilogramos, con hasta 2 decimales.', tipo: null, costo: null } })
      return undefined
    }
    setPrevia({ fase: 'cargando', fila: null })
    const control = new AbortController()
    const temporizador = setTimeout(() => {
      cotizarBultos({ codigoReserva: codigo, bultos: [{ descripcion: borrador.descripcion || 'x', pesoKg: peso }] }, control.signal)
        .then((datos) => setPrevia({ fase: 'listo', fila: datos.bultos[0] }))
        .catch((fallo) => {
          if (fallo.name === 'AbortError') return
          setPrevia({ fase: 'error', fila: null })
        })
    }, 300)
    return () => {
      clearTimeout(temporizador)
      control.abort()
    }
  }, [formalizable, codigo, borrador.pesoKg, borrador.descripcion])

  function cambiarBorrador(evento) {
    const { name, value } = evento.target
    setBorrador((anterior) => ({ ...anterior, [name]: value }))
    setErrorBorrador('')
  }

  function agregarBulto() {
    const descripcion = borrador.descripcion.trim()
    if (descripcion === '') return setErrorBorrador('Describe el bulto.')
    if (borrador.pesoKg.trim() === '') return setErrorBorrador('Ingresa el peso del bulto.')
    if (previa.fase !== 'listo' || previa.fila?.error) return setErrorBorrador(previa.fila?.error ?? 'Espera a que se calcule el costo del bulto.')
    setBultos((anteriores) => [...anteriores, { id: siguienteId.current++, descripcion, pesoKg: borrador.pesoKg.trim().replace(',', '.') }])
    setBorrador({ descripcion: '', pesoKg: '' })
    setErroresServidor({})
  }

  function quitarBulto(id) {
    setBultos((anteriores) => anteriores.filter((b) => b.id !== id))
    setErroresServidor({})
  }

  async function guardar(cobrarAhora) {
    setErrorGeneral('')
    setErroresServidor({})
    if (bultos.length === 0) {
      setErroresServidor({ bultos: 'Agrega al menos un bulto.' })
      return
    }
    if (tipoEntrega === 'DOMICILIO' && direccion.trim() === '') {
      setErroresServidor({ direccionEntrega: 'Ingresa la dirección de entrega.' })
      return
    }

    setGuardando(true)
    try {
      const envio = await formalizarEnvio({
        codigoReserva: codigo,
        tipoEntrega,
        direccionEntrega: tipoEntrega === 'DOMICILIO' ? direccion.trim() : '',
        bultos: bultos.map(({ descripcion, pesoKg }) => ({ descripcion, pesoKg })),
      })
      navegar(`/administrador/envio/${envio.codigoSeguimiento}`, { replace: true, state: { envio, cobrarAhora } })
    } catch (fallo) {
      setGuardando(false)
      const delServidor = fallo.campos ?? {}
      if (Object.keys(delServidor).length > 0) setErroresServidor(delServidor)
      else setErrorGeneral(fallo.message)
      if (fallo.estado === 409) setIntento((n) => n + 1)
    }
  }

  if (reserva.fase === 'cargando') {
    return (
      <p role="status" className="cargando">
        Cargando la reserva…
      </p>
    )
  }
  if (reserva.fase === 'no_encontrada') {
    return (
      <section className="confirmacion panel">
        <h1>No encontramos esa reserva</h1>
        <p>{reserva.mensaje}</p>
        <div className="acciones-confirmacion">
          <Link to="/administrador" className="boton boton-azul">
            Volver a Ventanilla
          </Link>
        </div>
      </section>
    )
  }
  if (reserva.fase === 'error') {
    return (
      <div className="resultado error" role="alert">
        <strong>No se pudo cargar la reserva.</strong> {reserva.mensaje}{' '}
        <button type="button" className="boton boton-claro" onClick={() => setIntento((n) => n + 1)}>
          Reintentar
        </button>
      </div>
    )
  }

  const r = reserva.datos
  if (!formalizable) {
    const motivo =
      r.estado === 'FORMALIZADA'
        ? 'Esta reserva ya fue formalizada.'
        : r.estado === 'CANCELADA'
          ? 'Esta reserva fue cancelada.'
          : 'Esta reserva venció: pasaron las 72 horas de vigencia.'
    return (
      <section className="confirmacion panel">
        <h1>No se puede formalizar {r.codigo}</h1>
        <p>{motivo}</p>
        <div className="acciones-confirmacion">
          {r.codigoSeguimiento && (
            <Link to={`/administrador/envio/${r.codigoSeguimiento}`} className="boton boton-marino">
              Ver el envío {r.codigoSeguimiento}
            </Link>
          )}
          <Link to="/administrador" className="boton boton-azul">
            Volver a Ventanilla
          </Link>
        </div>
      </section>
    )
  }

  const total = cotizacion.datos
  const filas = cotizacion.datos?.bultos ?? []
  const distancia = total?.distanciaKm ?? previa.fila?.distanciaKm

  return (
    <section className="formalizar">
      <div className="cabecera-ilustrada">
        <h1>Formalizar {r.codigo}</h1>
        <Mosaico ids={[13, 1, 4, 5]} />
      </div>
      <p className="subtitulo">
        {r.origen.nombre} ({r.origen.codigo}) → {r.destino.nombre} ({r.destino.codigo}){distancia ? ` · ${distancia} km` : ''}
      </p>
      <p className="ayuda">
        Remitente: {r.remitente.nombreCompleto} · Destinatario: {r.destinatario.nombreCompleto} · {r.contenido} · vence el {fechaHoraCorta(r.venceEl)}
      </p>

      {errorGeneral && (
        <div className="resultado error" role="alert">
          {errorGeneral}{' '}
          <Link to="/administrador">Volver a Ventanilla</Link>
        </div>
      )}

      <div className="nueva-reserva-contenido">
      <div className="panel formulario-formalizar">
        <div className="fila-doble">
          <CampoTexto id="tipoEntrega" name="tipoEntrega" etiqueta="Tipo de entrega" como="select" value={tipoEntrega} onChange={(e) => setTipoEntrega(e.target.value)} error={erroresServidor.tipoEntrega}>
            <option value="AGENCIA">AGENCIA</option>
            <option value="DOMICILIO">DOMICILIO</option>
          </CampoTexto>
          <CampoTexto id="direccionEntrega" name="direccionEntrega" etiqueta="Dirección de entrega" maxLength={150} placeholder="Solo si es DOMICILIO" disabled={tipoEntrega !== 'DOMICILIO'} value={tipoEntrega === 'DOMICILIO' ? direccion : ''} onChange={(e) => setDireccion(e.target.value)} error={erroresServidor.direccionEntrega} />
        </div>

        <h2>Bultos</h2>
        {erroresServidor.bultos && (
          <p className="campo-error" role="alert">
            {erroresServidor.bultos}
          </p>
        )}

        {cotizacion.fase === 'error' && (
          <div className="resultado error" role="alert">
            No se pudieron calcular los costos. {cotizacion.mensaje}
          </div>
        )}

        <div className="tabla-envoltorio">
          <table className="tabla-reservas tabla-bultos">
            <caption className="solo-lectores">Bultos del envío</caption>
            <thead>
              <tr>
                <th scope="col">N°</th>
                <th scope="col">Descripción</th>
                <th scope="col">Peso</th>
                <th scope="col">Tipo (por peso)</th>
                <th scope="col">Costo (por ruta)</th>
                <th scope="col">
                  <span className="solo-lectores">Acción</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {bultos.length === 0 && (
                <tr className="fila-vacia">
                  <td colSpan={6}>Aún no agregaste bultos.</td>
                </tr>
              )}
              {bultos.map((bulto, i) => {
                const fila = filas[i]
                const errorFila = erroresServidor[`bultos.${i + 1}.pesoKg`] ?? fila?.error
                return (
                  <tr key={bulto.id}>
                    <th scope="row">{i + 1}</th>
                    <td data-etiqueta="Descripción">
                      {bulto.descripcion}
                      {erroresServidor[`bultos.${i + 1}.descripcion`] && <span className="campo-error"> {erroresServidor[`bultos.${i + 1}.descripcion`]}</span>}
                    </td>
                    <td data-etiqueta="Peso">{Number(bulto.pesoKg).toFixed(2)} kg</td>
                    <td data-etiqueta="Tipo (por peso)">{fila?.tipo ?? (cotizacion.fase === 'cargando' ? '…' : '—')}</td>
                    <td data-etiqueta="Costo (por ruta)">
                      {fila?.costo ? `S/ ${fila.costo}` : cotizacion.fase === 'cargando' ? '…' : '—'}
                      {errorFila && <span className="campo-error"> {errorFila}</span>}
                    </td>
                    <td className="celda-accion" data-etiqueta="Acción">
                      <button type="button" className="boton boton-claro" onClick={() => quitarBulto(bulto.id)} aria-label={`Quitar el bulto ${i + 1}`}>
                        Quitar
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {tarifasRuta.length > 0 && (
          <div className="rangos-formalizar">
            <h3>Rangos de peso de esta ruta</h3>
            <RangosPeso tarifas={tarifasRuta} resaltado={previa.fase === 'listo' && !previa.fila?.error ? (previa.fila?.tipo ?? '') : ''} conPrecio />
            <p className="ayuda">El peso del bulto decide su tipo. Los límites y precios son los de la tarifa vigente de la ruta.</p>
          </div>
        )}

        <div className="nuevo-bulto" role="group" aria-label="Agregar un bulto">
          <CampoTexto id="descripcionBulto" name="descripcion" etiqueta="Descripción" maxLength={150} value={borrador.descripcion} onChange={cambiarBorrador} />
          <CampoTexto id="pesoBulto" name="pesoKg" etiqueta="Peso (kg)" inputMode="decimal" maxLength={9} value={borrador.pesoKg} onChange={cambiarBorrador} />
          <div className="previa-bulto" role="status" aria-live="polite">
            {previa.fase === 'cargando' && <span className="ayuda">Calculando…</span>}
            {previa.fase === 'listo' && previa.fila?.error && <span className="campo-error">{previa.fila.error}</span>}
            {previa.fase === 'listo' && !previa.fila?.error && previa.fila?.costo && (
              <span className="ayuda">
                {previa.fila.tipo} · S/ {previa.fila.costo}
              </span>
            )}
            {previa.fase === 'error' && <span className="campo-error">No se pudo calcular el costo.</span>}
          </div>
          <button type="button" className="boton boton-marino" onClick={agregarBulto}>
            + Agregar bulto
          </button>
        </div>
        {errorBorrador && (
          <p className="campo-error" role="alert">
            {errorBorrador}
          </p>
        )}

        <div className="resumen-envio" aria-live="polite">
          <p className="importe">
            Importe <strong>S/ {total?.importe ?? '0.00'}</strong>
          </p>
          <p className="ayuda">
            {total?.cantidadBultos ?? 0} {total?.cantidadBultos === 1 ? 'bulto' : 'bultos'} · {total?.pesoTotalKg ?? '0.00'} kg · el código de seguimiento se genera al guardar y se imprime en la etiqueta
          </p>
        </div>

        <p className="ayuda">Ambos botones guardan el envío con el pago pendiente: el cobro no se registra aquí, se hará en Caja.</p>
        <div className="acciones-formulario">
          <button type="button" className="boton boton-azul" onClick={() => guardar(true)} disabled={guardando || bultos.length === 0 || cotizacion.fase === 'cargando' || cotizacion.datos?.valido === false}>
            {guardando ? 'Guardando…' : 'Guardar y cobrar ahora'}
          </button>
          <button type="button" className="boton boton-marino" onClick={() => guardar(false)} disabled={guardando || bultos.length === 0 || cotizacion.fase === 'cargando' || cotizacion.datos?.valido === false}>
            Guardar · paga en destino
          </button>
          <Link to="/administrador" className="boton boton-claro">
            Cancelar
          </Link>
        </div>
      </div>

      <aside className="panel-precio" aria-label="Resumen del envío">
        <RutaMonedas origen={r.origen.departamento} destino={r.destino.departamento} nombreOrigen={r.origen.nombre} nombreDestino={r.destino.nombre} tamano={80} />
        <h2>Código de seguimiento</h2>
        <p className="ayuda">Se genera al guardar el envío y se imprime en la etiqueta.</p>
        <p>
          {total?.cantidadBultos ?? 0} {total?.cantidadBultos === 1 ? 'bulto' : 'bultos'} · {total?.pesoTotalKg ?? '0.00'} kg
        </p>
      </aside>
      </div>
    </section>
  )
}
