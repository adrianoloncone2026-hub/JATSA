import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { ingresar as ingresarApi, obtenerSesion, salir as salirApi } from '../api/auth.js'

const AuthContext = createContext(null)

// Guarda el usuario de la sesión. Al cargar la aplicación consulta al backend si hay sesión abierta.
export function AuthProvider({ children }) {
  const [estado, setEstado] = useState({ usuario: null, cargando: true })

  useEffect(() => {
    const control = new AbortController()
    obtenerSesion(control.signal)
      .then((usuario) => setEstado({ usuario, cargando: false }))
      .catch((error) => {
        if (error.name === 'AbortError') return
        setEstado({ usuario: null, cargando: false })
      })
    return () => control.abort()
  }, [])

  const ingresar = useCallback(async (credenciales) => {
    const usuario = await ingresarApi(credenciales)
    setEstado({ usuario, cargando: false })
    return usuario
  }, [])

  // El cierre solo se refleja en la interfaz cuando el servidor lo confirma. Si la petición falla,
  const salir = useCallback(async () => {
    await salirApi()
    setEstado({ usuario: null, cargando: false })
  }, [])

  const valor = useMemo(
    () => ({ usuario: estado.usuario, cargando: estado.cargando, ingresar, salir }),
    [estado, ingresar, salir],
  )

  return <AuthContext.Provider value={valor}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const contexto = useContext(AuthContext)
  if (contexto === null) throw new Error('useAuth debe usarse dentro de <AuthProvider>')
  return contexto
}
