import { useCallback, useEffect, useState } from 'react'
import { obtenerSalud } from '../../api/sistema.js'

// Prueba de comunicación React <-> PHP. No depende de la base de datos.
export default function PruebaConexion() {
  const [estado, setEstado] = useState({ fase: 'cargando' })

  const comprobar = useCallback((signal) => {
    setEstado({ fase: 'cargando' })
    obtenerSalud(signal)
      .then((datos) => setEstado({ fase: 'ok', datos }))
      .catch((error) => {
        if (error.name === 'AbortError') return
        setEstado({ fase: 'error', mensaje: error.message, codigo: error.codigo })
      })
  }, [])

  useEffect(() => {
    const control = new AbortController()
    comprobar(control.signal)
    return () => control.abort()
  }, [comprobar])

  return (
    <section>
      <h1>Prueba de conexión</h1>
      <p>Comprueba que el frontend (React) se comunica con el backend (PHP) mediante <code>GET /api/salud</code>.</p>

      {estado.fase === 'cargando' && <p role="status">Consultando la API…</p>}

      {estado.fase === 'ok' && (
        <div className="resultado ok" role="status">
          <strong>Conectado.</strong> {estado.datos.servicio} v{estado.datos.version} — hora del servidor:{' '}
          {estado.datos.hora_servidor}
        </div>
      )}

      {estado.fase === 'error' && (
        <div className="resultado error" role="alert">
          <strong>Sin conexión con la API.</strong> {estado.mensaje} ({estado.codigo})
        </div>
      )}

      <button type="button" onClick={() => comprobar()}>
        Volver a comprobar
      </button>
    </section>
  )
}
