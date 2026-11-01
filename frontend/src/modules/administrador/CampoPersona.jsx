import { useEffect, useState } from 'react'
import { consultarPersona } from '../../api/ventanilla.js'
import CampoTexto from '../../components/CampoTexto.jsx'
import { errorDocumentoPersonal, sinSeparadores } from '../../utils/validaciones.js'

// Documento de una persona (remitente o destinatario) con su nombre autocompletado. Si el documento no existe
// pide nombre y teléfono para crearla. Los campos se llaman «<rol>TipoDocumento», «<rol>NumeroDocumento»,
// «<rol>Nombre» y «<rol>Telefono», igual que en POST /api/ventanilla/reservas.
//
// Cada cambio de documento cancela la consulta anterior: un resultado lento nunca se muestra para otro documento.
export default function CampoPersona({ rol, etiqueta, valores, errores, onCambio, onEstado }) {
  const tipo = valores[`${rol}TipoDocumento`]
  const numero = valores[`${rol}NumeroDocumento`]
  const [busqueda, setBusqueda] = useState({ fase: 'inactivo' }) // inactivo | buscando | existe | nuevo | error

  useEffect(() => {
    if (errorDocumentoPersonal(tipo, numero)) {
      setBusqueda({ fase: 'inactivo' })
      return undefined
    }
    setBusqueda({ fase: 'buscando' })
    const control = new AbortController()
    const temporizador = setTimeout(() => {
      consultarPersona({ tipoDocumento: tipo, numeroDocumento: sinSeparadores(numero) }, control.signal)
        .then((respuesta) =>
          setBusqueda(respuesta.existe ? { fase: 'existe', nombre: respuesta.nombreCompleto } : { fase: 'nuevo' }),
        )
        .catch((fallo) => {
          if (fallo.name === 'AbortError') return
          setBusqueda({ fase: 'error', mensaje: fallo.message })
        })
    }, 350)
    return () => {
      clearTimeout(temporizador)
      control.abort()
    }
  }, [tipo, numero])

  // La pantalla necesita saber si esta persona es nueva para validar sus datos.
  useEffect(() => {
    onEstado?.(rol, busqueda.fase)
  }, [busqueda.fase, rol, onEstado])

  const pideDatos = busqueda.fase === 'nuevo' || Boolean(errores[`${rol}Nombre`] || errores[`${rol}Telefono`])
  const esRuc = tipo === 'RUC'

  return (
    <fieldset className="campo-persona">
      <legend>{etiqueta}</legend>
      <div className="fila-destinatario">
        <CampoTexto id={`${rol}TipoDocumento`} name={`${rol}TipoDocumento`} etiqueta="Tipo" como="select" value={tipo} onChange={onCambio}>
          <option value="DNI">DNI</option>
          <option value="RUC">RUC</option>
          <option value="CE">CE</option>
          <option value="PAS">PAS</option>
        </CampoTexto>
        <CampoTexto id={`${rol}NumeroDocumento`} name={`${rol}NumeroDocumento`} etiqueta="Número" autoComplete="off" maxLength={14} value={numero} onChange={onCambio} error={errores[`${rol}NumeroDocumento`]} />
        <div className="estado-destinatario" role="status" aria-live="polite">
          {busqueda.fase === 'buscando' && <span className="destinatario-buscando">Buscando…</span>}
          {busqueda.fase === 'existe' && <span className="destinatario-existe">✓ {busqueda.nombre}</span>}
          {busqueda.fase === 'nuevo' && <span className="destinatario-aviso">Persona nueva: ingresa sus datos.</span>}
          {busqueda.fase === 'error' && <span className="destinatario-aviso">No se pudo consultar el documento. {busqueda.mensaje}</span>}
        </div>
      </div>

      {pideDatos && (
        <div className="fila-doble">
          <CampoTexto id={`${rol}Nombre`} name={`${rol}Nombre`} etiqueta={esRuc ? 'Razón social' : 'Nombres y apellidos'} maxLength={150} value={valores[`${rol}Nombre`]} onChange={onCambio} error={errores[`${rol}Nombre`]} />
          <CampoTexto id={`${rol}Telefono`} name={`${rol}Telefono`} etiqueta="Teléfono" type="tel" inputMode="numeric" maxLength={13} value={valores[`${rol}Telefono`]} onChange={onCambio} error={errores[`${rol}Telefono`]} />
        </div>
      )}
    </fieldset>
  )
}
