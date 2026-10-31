// Estado de una reserva con su color (como en las diapositivas 11 y 15).
const ETIQUETAS = {
  PENDIENTE: 'PENDIENTE',
  FORMALIZADA: 'FORMALIZADA',
  CANCELADA: 'CANCELADA',
  VENCIDA: 'VENCIDA',
}

export default function InsigniaEstado({ estado }) {
  return <span className={`insignia insignia-${String(estado).toLowerCase()}`}>{ETIQUETAS[estado] ?? estado}</span>
}
