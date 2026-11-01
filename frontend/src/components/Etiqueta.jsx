import { fechaHoraCorta } from '../utils/formato.js'

// Etiqueta del envío para imprimir (diapositiva 19: «se imprime en la etiqueta»). Se imprime desde el navegador
// con los estilos de impresión de global.css: solo esta etiqueta sale en el papel.
export default function Etiqueta({ envio }) {
  const { reserva } = envio
  return (
    <div className="etiqueta" aria-label={`Etiqueta del envío ${envio.codigoSeguimiento}`}>
      <p className="etiqueta-marca">JATSA Cargo</p>
      <p className="etiqueta-codigo">{envio.codigoSeguimiento}</p>
      <p className="etiqueta-ruta">
        {reserva.origen.nombre} → {reserva.destino.nombre}
      </p>
      <p>
        <strong>Destinatario:</strong> {reserva.destinatario.nombreCompleto}
      </p>
      <p>
        <strong>Entrega:</strong> {envio.tipoEntrega === 'DOMICILIO' ? `A domicilio · ${envio.direccionEntrega}` : `En agencia ${reserva.destino.nombre}`}
      </p>
      <p>
        {envio.cantidadBultos} {envio.cantidadBultos === 1 ? 'bulto' : 'bultos'} · {envio.pesoTotalKg} kg
      </p>
      <p className="etiqueta-pie">
        Reserva {reserva.codigo} · {fechaHoraCorta(envio.fechaHoraRegistro)}
      </p>
    </div>
  )
}
