// Marcador de una pantalla que aún no se implementa.
export default function PantallaPendiente({ titulo, modulo, seccion }) {
  return (
    <section className="pendiente">
      <h1>{titulo}</h1>
      <p>
        Pantalla pendiente de {seccion}. Se implementa en el <strong>módulo {modulo}</strong> de
        <code> docs/orden-implementacion.md</code>.
      </p>
    </section>
  )
}
