// Estado de una reserva, un pago, un viaje o un envío con su color (diapositivas 3, 11, 14, 15, 16, 20, 21, 22 y 24).
const ETIQUETAS = {
  PENDIENTE: 'PENDIENTE',
  FORMALIZADA: 'FORMALIZADA',
  CANCELADA: 'CANCELADA',
  VENCIDA: 'VENCIDA',
  PROGRAMADO: 'PROGRAMADO',
  EN_RUTA: 'EN RUTA',
  TERMINADO: 'TERMINADO',
  EN_TRANSITO: 'EN TRÁNSITO',
  EN_DESTINO: 'EN DESTINO',
  ENTREGADO: 'ENTREGADO',
  DEVUELTO: 'DEVUELTO',
  ANULADO: 'ANULADO',
}

export default function InsigniaEstado({ estado }) {
  return <span className={`insignia insignia-${String(estado).toLowerCase()}`}>{ETIQUETAS[estado] ?? estado}</span>
}
