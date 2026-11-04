// Tarjeta con una cifra grande y su explicación (Resumen y Viajes y flota). `variacion`: porcentaje entero respecto del mes
// anterior, o null si no hay con qué comparar. No tiene lógica de negocio.
export default function TarjetaIndicador({ valor, titulo, detalle, variacion, referencia }) {
  const hayVariacion = variacion !== undefined
  return (
    <div className="tarjeta-indicador">
      <strong className="indicador-valor">{valor}</strong>
      <span className="indicador-titulo">{titulo}</span>
      {hayVariacion && (
        <span className="indicador-detalle">
          {variacion === null ? 'sin mes anterior para comparar' : `${variacion > 0 ? '+' : ''}${variacion} % que ${referencia}`}
        </span>
      )}
      {detalle && <span className="indicador-detalle">{detalle}</span>}
    </div>
  )
}
