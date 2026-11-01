import { Link } from 'react-router-dom'
import { useState } from 'react'
import { useAuth } from '../../auth/AuthProvider.jsx'
import { rutaInicioDeRol } from '../../auth/roles.js'
import FormularioContrasena from './FormularioContrasena.jsx'

// «Cambiar contraseña» del personal (gerente, administrador y transportista). Solo permite cambiar la PROPIA contraseña: no muestra
// ni edita datos personales (eso sigue siendo de «Mi cuenta» de cliente y empresario) ni ninguna función de cliente. El servidor
// actúa siempre sobre la cuenta de la sesión, pide la contraseña actual y cierra las demás sesiones de esta cuenta.
export default function CambiarContrasena() {
  const { usuario } = useAuth()
  const [aviso, setAviso] = useState('')
  const [intento, setIntento] = useState(0) // al guardar, el formulario se vacía volviendo a montarlo

  return (
    <section className="cambiar-contrasena">
      <h1>Cambiar contraseña</h1>
      <p className="ayuda">
        Cambia la contraseña de tu cuenta ({usuario.nombreUsuario}). Si entraste con una contraseña temporal que te entregó el gerente, cámbiala ahora por una propia.
      </p>
      {aviso && (
        <div className="resultado ok" role="status">
          {aviso}
        </div>
      )}
      <div className="panel">
        <FormularioContrasena
          key={intento}
          usuario={usuario.nombreUsuario}
          alCancelar={() => setIntento((n) => n + 1)}
          alGuardar={() => {
            setAviso('Contraseña actualizada. Las demás sesiones abiertas de esta cuenta se cerraron.')
            setIntento((n) => n + 1)
          }}
        />
      </div>
      <p>
        <Link to={rutaInicioDeRol(usuario.rol)} className="boton boton-claro">
          Volver
        </Link>
      </p>
    </section>
  )
}