import { fechaHoraBreve } from '../utils/formato.js'

// Carga de un viaje: envío, bultos, peso, destino final y cuándo se cargó (diapositiva 25). Con `renderAccion` añade una columna
// de acción por envío (p. ej. «Quitar» en Despacho). No tiene lógica de negocio.
export default function TablaCarga({ carga, renderAccion }) {
  return (
    <div className="tabla-envoltorio">
      <table className="tabla-reservas tabla-carga">
        <caption className="solo-lectores">Carga del viaje</caption>
        <thead>
          <tr>
            <th scope="col">Envío</th>
            <th scope="col">Bultos</th>
            <th scope="col">Peso</th>
            <th scope="col">Destino final</th>
            <th scope="col">Cargado</th>
            {renderAccion && <th scope="col">Acción</th>}
          </tr>
        </thead>
        <tbody>
          {carga.map((c) => (
            <tr key={c.codigoSeguimiento}>
              <th scope="row">{c.codigoSeguimiento}</th>
              <td data-etiqueta="Bultos">{c.bultos}</td>
              <td data-etiqueta="Peso">{c.pesoKg} kg</td>
              <td data-etiqueta="Destino final">{c.destinoFinal.nombre}</td>
              <td data-etiqueta="Cargado">{fechaHoraBreve(c.cargadoEn)}</td>
              {renderAccion && (
                <td className="celda-accion" data-etiqueta="Acción">
                  {renderAccion(c)}
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
