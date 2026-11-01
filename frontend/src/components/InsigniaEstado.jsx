// Estado de una reserva, un pago o un viaje con su color (diapositivas 11, 15, 16, 20, 21 y 24).
const ETIQUETAS = {
  PENDIENTE: 'PENDIENTE',
  FORMALIZADA: 'FORMALIZADA',
  CANCELADA: 'CANCELADA',
  VENCIDA: 'VENCIDA',
  PROGRAMADO: 'PROGRAMADO',
  EN_RUTA: 'EN RUTA',
  TERMINADO: 'TERMINADO',
}

export default function InsigniaEstado({ estado }) {
  return <span className={`insignia insignia-${String(estado).toLowerCase()}`}>{ETIQUETAS[estado] ?? estado}</span>
}
