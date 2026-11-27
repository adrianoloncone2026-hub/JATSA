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
  ACTIVA: 'ACTIVA',
  INACTIVA: 'INACTIVA',
  VIGENTE: 'vigente',
}

export default function InsigniaEstado({ estado }) {
  return <span className={`insignia insignia-${String(estado).toLowerCase()}`}>{ETIQUETAS[estado] ?? estado}</span>
}
