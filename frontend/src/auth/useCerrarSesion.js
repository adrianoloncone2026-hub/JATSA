import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from './AuthProvider.jsx'

// Cerrar sesión solo cuando el servidor lo confirma. Si falla, se conserva el usuario y se muestra un aviso
export function useCerrarSesion() {
  const { salir } = useAuth()
  const navegar = useNavigate()
  const [saliendo, setSaliendo] = useState(false)
  const [errorSalida, setErrorSalida] = useState('')

  const cerrarSesion = useCallback(async () => {
    setErrorSalida('')
    setSaliendo(true)
    try {
      await salir()
      navegar('/', { replace: true })
    } catch (error) {
      setErrorSalida(`No se pudo cerrar la sesión. ${error.message} Sigues con la sesión abierta.`)
    } finally {
      setSaliendo(false)
    }
  }, [salir, navegar])

  return { cerrarSesion, saliendo, errorSalida }
}
