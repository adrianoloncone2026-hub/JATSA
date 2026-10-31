// Pruebas de «Crear cuenta», «Mi cuenta» y la identidad en la cabecera (módulo 3) en un navegador real.
// Usa el arnés común (arnes.mjs): la API está interceptada, sin PHP ni base de datos.
//
// Uso:  1) en frontend/:  npm run dev      2) desde C:\Jatsa:  node frontend/tests/e2e/cuenta.mjs
import { crearInforme, esperar, iniciarNavegador } from './arnes.mjs'

const error = (estado, codigo, mensaje, campos) => ({ estado, cuerpo: { error: { codigo, mensaje, ...(campos ? { campos } : {}) } } })
const exito = (datos, estado = 200) => ({ estado, cuerpo: { data: datos } })

// Estado de la «base de datos» simulada: lo que la API devuelve y cambia durante la prueba.
const estado = {
  sesion: null, // null = sin sesión; objeto = usuario de la sesión
  persona: { tipoDocumento: 'DNI', numeroDocumento: '71889345', nombreCompleto: 'María Elena Rojas Ccori', telefono: '987654323', telefonoAuxiliar: null, correo: null },
  rol: 'CLIENTE',
  registro: () => exito({ usuario: 'anatorres', rol: 'CLIENTE' }, 201), // lo que responde el registro
  cierreOk: true,
}
const usuarioDe = (rol, nombreCompleto) => ({ id: 4, nombreUsuario: 'mariarojas', nombreCompleto, rol, sucursal: null })
const cuenta = () => ({
  persona: estado.persona,
  usuario: { nombreUsuario: 'mariarojas', rol: estado.rol, activo: true, fechaCreacion: '2026-03-01' },
})

const { comprobar, titulo, terminar } = crearInforme()
const navegador = await iniciarNavegador(9341, ({ metodo, ruta, cuerpo }) => {
  if (ruta === '/api/auth/yo') return estado.sesion ? exito({ usuario: estado.sesion }) : error(401, 'no_autenticado', 'Debes iniciar sesión.')
  if (ruta === '/api/auth/salir') {
    if (!estado.cierreOk) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    estado.sesion = null
    return exito({ sesionCerrada: true })
  }
  if (ruta === '/api/cuenta/registro' && metodo === 'POST') return estado.registro(cuerpo)
  if (ruta === '/api/cuenta' && metodo === 'GET') return exito(cuenta())
  if (ruta === '/api/cuenta' && metodo === 'PATCH') {
    if (cuerpo.telefono === '900000000') return error(422, 'datos_invalidos', 'Revisa los datos del formulario.', { telefono: 'El teléfono ya no es válido (error del servidor).' })
    estado.persona = { ...estado.persona, telefono: cuerpo.telefono, telefonoAuxiliar: cuerpo.telefonoAuxiliar || null, correo: cuerpo.correo || null }
    return exito(cuenta())
  }
  if (ruta === '/api/cuenta/contrasena' && metodo === 'POST') {
    if (cuerpo.contrasenaActual !== 'ClaveActual#1') return error(422, 'contrasena_incorrecta', 'La contraseña actual no es correcta.', { contrasenaActual: 'La contraseña actual no es correcta.' })
    return exito({ contrasenaCambiada: true })
  }
  return null
})
const { irA, escribir, pulsar, texto, valor, existe, ruta, enfocado, evaluar, llamadas } = navegador

const llamadasA = (metodo, rutaApi) => llamadas.filter((l) => l.metodo === metodo && l.ruta === rutaApi)
const REGISTRO = '/api/cuenta/registro'
const ENVIAR = '.formulario-cuenta button[type=submit]'
const errorDe = (id) => texto(`#${id}-error`)
const textosDeError = () => evaluar(`[...document.querySelectorAll('.campo-error')].map(e => e.innerText).join(' | ')`)

async function llenarRegistro(datos) {
  for (const [id, v] of Object.entries(datos)) await escribir(`#${id}`, v)
}
const REGISTRO_VALIDO = {
  numeroDocumento: '45678912', nombreCompleto: 'Ana Torres Díaz', telefono: '911 222 333',
  usuario: 'anatorres', contrasena: 'Segura#2026', confirmacion: 'Segura#2026',
}

try {
  titulo('Crear cuenta: validaciones en el formulario (sin llamar a la API)')
  await irA('/crear-cuenta')
  comprobar('muestra el título y el subtítulo del prototipo', [await texto('h1'), await texto('.subtitulo')], ['Crear cuenta', 'Con DNI se crea una cuenta CLIENTE; con RUC, una cuenta EMPRESARIO'])
  comprobar('la nota sobre los datos guardados menciona el DNI', await texto('.nota-datos'), 'Si ya enviaste o recibiste con este DNI, usamos tus datos guardados')
  await escribir('#tipoDocumento', 'RUC')
  comprobar('con RUC cambian la etiqueta del nombre y la nota', [await texto('label[for=nombreCompleto]'), await texto('.nota-datos')], ['Razón social', 'Si ya enviaste o recibiste con este RUC, usamos tus datos guardados'])
  await escribir('#tipoDocumento', 'DNI')

  await pulsar(ENVIAR)
  await esperar(300)
  // Obligatorios vacíos: documento, nombre, teléfono, usuario y contraseña (la confirmación vacía coincide).
  comprobar('enviar vacío marca los 5 campos obligatorios y no llama a la API', [(await textosDeError()).split(' | ').length, llamadasA('POST', REGISTRO).length], [5, 0])
  comprobar('el foco va al primer campo con error', await enfocado(), 'numeroDocumento')
  comprobar('el campo con error queda marcado para lectores de pantalla', await evaluar(`document.querySelector('#numeroDocumento').getAttribute('aria-invalid')`), 'true')
  await escribir('#numeroDocumento', '4567')
  comprobar('el error de un campo desaparece al editarlo', await existe('#numeroDocumento-error'), false)

  await llenarRegistro({ ...REGISTRO_VALIDO, numeroDocumento: '1234567', telefono: '98765432A', telefonoAuxiliar: '911222333', correo: 'no-es-correo', usuario: 'a b', contrasena: 'corta', confirmacion: 'otra' })
  await pulsar(ENVIAR)
  await esperar(300)
  comprobar('DNI de 7 dígitos', await errorDe('numeroDocumento'), 'El DNI tiene 8 dígitos.')
  comprobar('teléfono con letras', await errorDe('telefono'), 'El teléfono tiene 9 dígitos.')
  comprobar('auxiliar igual al teléfono (tras corregir el teléfono)', (await escribir('#telefono', '911 222 333'), await pulsar(ENVIAR), await esperar(200), await errorDe('telefonoAuxiliar')), 'El teléfono auxiliar debe ser distinto del teléfono.')
  comprobar('correo inválido', await errorDe('correo'), 'Ingresa un correo válido (hasta 120 caracteres).')
  comprobar('usuario con espacio', await errorDe('usuario'), 'El usuario debe tener de 4 a 30 caracteres: letras, números, punto, guion o guion bajo.')
  comprobar('contraseña corta', await errorDe('contrasena'), 'La contraseña debe tener al menos 8 caracteres.')
  comprobar('confirmación distinta', await errorDe('confirmacion'), 'Las contraseñas no coinciden.')
  comprobar('ninguna de esas validaciones llamó a la API', llamadasA('POST', REGISTRO).length, 0)

  await llenarRegistro({ ...REGISTRO_VALIDO, usuario: 'anatorres', contrasena: 'anatorres', confirmacion: 'anatorres', telefonoAuxiliar: '', correo: '' })
  await pulsar(ENVIAR)
  await esperar(300)
  comprobar('contraseña igual al usuario', await errorDe('contrasena'), 'La contraseña no puede ser igual al usuario.')

  titulo('Crear cuenta: envío correcto')
  await irA('/crear-cuenta')
  await llenarRegistro({ ...REGISTRO_VALIDO, telefonoAuxiliar: '922 333 444', correo: ' ana@mail.com ' })
  await pulsar(ENVIAR)
  await esperar(900)
  comprobar('lleva a «Ingresar» con aviso y el usuario escrito', [await ruta(), await texto('.resultado.ok'), await valor('#usuario')], ['/ingresar', 'Cuenta creada. Ingresa con tu usuario y tu contraseña.', 'anatorres'])
  comprobar('el cuerpo enviado va normalizado (sin espacios) y no incluye la confirmación', llamadasA('POST', REGISTRO).at(-1)?.cuerpo, {
    tipoDocumento: 'DNI', numeroDocumento: '45678912', nombreCompleto: 'Ana Torres Díaz', telefono: '911222333',
    telefonoAuxiliar: '922333444', correo: 'ana@mail.com', usuario: 'anatorres', contrasena: 'Segura#2026',
  })
  comprobar('registrarse no abre sesión (no hay barra de usuario)', await existe('.usuario-sesion'), false)

  titulo('Crear cuenta: respuestas del servidor')
  estado.registro = () => error(409, 'cuenta_existente', 'Ya existe una cuenta con ese documento. Si no recuerdas tu usuario o tu contraseña, acércate a tu agencia.')
  await irA('/crear-cuenta'); await llenarRegistro(REGISTRO_VALIDO); await pulsar(ENVIAR); await esperar(600)
  comprobar('documento que ya tiene cuenta: aviso arriba y sigue en el formulario', [await texto('.resultado.error'), await ruta()], ['Ya existe una cuenta con ese documento. Si no recuerdas tu usuario o tu contraseña, acércate a tu agencia.', '/crear-cuenta'])
  estado.registro = () => error(409, 'usuario_en_uso', 'Ese usuario ya está en uso.', { usuario: 'Ese usuario ya está en uso. Elige otro.' })
  await irA('/crear-cuenta'); await llenarRegistro(REGISTRO_VALIDO); await pulsar(ENVIAR); await esperar(600)
  comprobar('usuario en uso: el error aparece junto al campo y con el foco', [await errorDe('usuario'), await enfocado()], ['Ese usuario ya está en uso. Elige otro.', 'usuario'])
  estado.registro = () => error(422, 'datos_invalidos', 'Revisa los datos del formulario.', { telefono: 'El teléfono tiene 9 dígitos.', correo: 'Ingresa un correo válido (hasta 120 caracteres).' })
  await irA('/crear-cuenta'); await llenarRegistro(REGISTRO_VALIDO); await pulsar(ENVIAR); await esperar(600)
  comprobar('errores por campo del servidor (422): se muestran todos', await textosDeError(), 'El teléfono tiene 9 dígitos. | Ingresa un correo válido (hasta 120 caracteres).')
  estado.registro = () => error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
  await irA('/crear-cuenta'); await llenarRegistro(REGISTRO_VALIDO); await pulsar(ENVIAR); await esperar(600)
  comprobar('error del servidor: aviso arriba y el botón se reactiva', [await texto('.resultado.error'), await texto(ENVIAR)], ['Ocurrió un error interno. Inténtalo de nuevo más tarde.', 'Crear cuenta'])

  titulo('Quien ya tiene sesión no ve «Crear cuenta»')
  estado.sesion = usuarioDe('CLIENTE', 'María Elena Rojas Ccori')
  await irA('/crear-cuenta')
  comprobar('lo lleva a su panel', await ruta(), '/cliente')

  titulo('Mi cuenta (cliente)')
  estado.persona = { tipoDocumento: 'DNI', numeroDocumento: '71889345', nombreCompleto: 'María Elena Rojas Ccori', telefono: '987654323', telefonoAuxiliar: null, correo: null }
  await irA('/cliente/cuenta')
  comprobar('perfil: nombre, documento, usuario, rol, fecha y estado', [await texto('.perfil-tarjeta h2'), await texto('.perfil-tarjeta p'), await texto('.insignia')],
    ['María Elena Rojas Ccori', 'DNI 71889345 · usuario mariarojas · cuenta CLIENTE activa desde 01/03/2026', 'ACTIVA'])
  comprobar('avatar con las iniciales (MR)', await texto('.avatar-grande'), 'MR')
  comprobar('mis datos: los vacíos se muestran con una raya', await texto('.datos-contacto'), 'Teléfono 987654323 Teléfono auxiliar — Correo —')
  comprobar('cabecera: nombre y rol enlazan a Mi cuenta', [await texto('.identidad-nombre'), await texto('.identidad-rol'), await evaluar(`document.querySelector('a.identidad').getAttribute('href')`)], ['María Elena Rojas Ccori', 'CLIENTE', '/cliente/cuenta'])
  comprobar('menú del cliente: sin «Mi cuenta» y con Salidas y Rastrear', await evaluar(`[...document.querySelectorAll('.menu a')].map(a => a.innerText).join(' | ')`), 'Mi panel | Nueva reserva | Mis reservas | Historial | Mis pagos | Salidas | Rastrear')
  comprobar('acciones laterales', await evaluar(`[...document.querySelectorAll('.mi-cuenta-acciones > *')].map(e => e.innerText).join(' | ')`), 'Editar datos | Cambiar contraseña | Historial de envíos | Mis reservas | Mis pagos | Cerrar sesión')

  titulo('Mi cuenta: editar teléfonos y correo')
  await pulsar('.mi-cuenta-acciones button:nth-child(1)')
  await esperar(200)
  comprobar('«Editar datos» abre el formulario con los valores actuales', [await valor('#editar-telefono'), await valor('#editar-auxiliar'), await valor('#editar-correo')], ['987654323', '', ''])
  await escribir('#editar-telefono', '12'); await escribir('#editar-correo', 'x')
  await pulsar('form[aria-label="Editar mis datos"] button[type=submit]'); await esperar(200)
  comprobar('datos inválidos: se marcan y no se llama a la API', [await textosDeError(), llamadasA('PATCH', '/api/cuenta').length], ['El teléfono tiene 9 dígitos. | Ingresa un correo válido (hasta 120 caracteres).', 0])
  await escribir('#editar-telefono', '900 111 222'); await escribir('#editar-auxiliar', '933444555'); await escribir('#editar-correo', 'maria@mail.com')
  await pulsar('form[aria-label="Editar mis datos"] button[type=submit]'); await esperar(600)
  comprobar('guardar: aviso y los datos nuevos a la vista', [await texto('.resultado.ok'), await texto('.datos-contacto')], ['Datos actualizados.', 'Teléfono 900111222 Teléfono auxiliar 933444555 Correo maria@mail.com'])
  comprobar('se envía solo contacto, sin documento ni nombre', llamadasA('PATCH', '/api/cuenta').at(-1)?.cuerpo, { telefono: '900111222', telefonoAuxiliar: '933444555', correo: 'maria@mail.com' })
  await pulsar('.mi-cuenta-acciones button:nth-child(1)'); await esperar(200)
  await pulsar('form[aria-label="Editar mis datos"] button[type=button]'); await esperar(200)
  comprobar('«Cancelar» cierra el formulario sin llamar a la API', [await existe('form[aria-label="Editar mis datos"]'), llamadasA('PATCH', '/api/cuenta').length], [false, 1])
  await pulsar('.mi-cuenta-acciones button:nth-child(1)'); await esperar(200)
  await escribir('#editar-telefono', '900000000')
  await pulsar('form[aria-label="Editar mis datos"] button[type=submit]'); await esperar(600)
  comprobar('error del servidor por campo: se muestra junto al campo y el formulario sigue abierto', [await errorDe('editar-telefono'), await existe('form[aria-label="Editar mis datos"]')], ['El teléfono ya no es válido (error del servidor).', true])
  await pulsar('form[aria-label="Editar mis datos"] button[type=button]'); await esperar(200)

  titulo('Mi cuenta: cambiar contraseña')
  await pulsar('.mi-cuenta-acciones button:nth-child(2)'); await esperar(200)
  await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await esperar(200)
  comprobar('vacío: se pide la actual y la nueva, sin llamar a la API', [await textosDeError(), llamadasA('POST', '/api/cuenta/contrasena').length], ['Ingresa tu contraseña actual. | La contraseña debe tener al menos 8 caracteres.', 0])
  await escribir('#contrasena-actual', 'ClaveActual#1'); await escribir('#contrasena-nueva', 'ClaveActual#1'); await escribir('#contrasena-confirmacion', 'ClaveActual#1')
  await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await esperar(200)
  comprobar('la nueva no puede ser igual a la actual', await errorDe('contrasena-nueva'), 'La contraseña nueva debe ser distinta de la actual.')
  await escribir('#contrasena-nueva', 'mariarojas'); await escribir('#contrasena-confirmacion', 'mariarojas')
  await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await esperar(200)
  comprobar('la nueva no puede ser igual al usuario', await errorDe('contrasena-nueva'), 'La contraseña no puede ser igual al usuario.')
  await escribir('#contrasena-nueva', 'NuevaClave#2026'); await escribir('#contrasena-confirmacion', 'Otra#2026xx')
  await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await esperar(200)
  comprobar('confirmación distinta', await errorDe('contrasena-confirmacion'), 'Las contraseñas no coinciden.')
  await escribir('#contrasena-actual', 'Equivocada#1'); await escribir('#contrasena-confirmacion', 'NuevaClave#2026')
  await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await esperar(600)
  comprobar('contraseña actual incorrecta (del servidor): error junto al campo', await errorDe('contrasena-actual'), 'La contraseña actual no es correcta.')
  await escribir('#contrasena-actual', 'ClaveActual#1')
  await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await esperar(600)
  comprobar('cambio correcto: aviso y el formulario se cierra', [await texto('.resultado.ok'), await existe('form[aria-label="Cambiar contraseña"]')], ['Contraseña actualizada. Las demás sesiones abiertas de esta cuenta se cerraron.', false])
  comprobar('se envían la actual y la nueva, sin la confirmación', llamadasA('POST', '/api/cuenta/contrasena').at(-1)?.cuerpo, { contrasenaActual: 'ClaveActual#1', contrasenaNueva: 'NuevaClave#2026' })
  comprobar('la contraseña no queda escrita en la página', await evaluar(`document.body.innerHTML.includes('NuevaClave#2026')`), false)

  titulo('Mi cuenta: cerrar sesión')
  estado.cierreOk = false
  await pulsar('.mi-cuenta-acciones button.boton-marino'); await esperar(600)
  comprobar('si el servidor no confirma, sigue dentro y lo avisa', [await ruta(), await existe('.perfil'), await texto('.mi-cuenta-acciones .mensaje-error')],
    ['/cliente/cuenta', true, 'No se pudo cerrar la sesión. Ocurrió un error interno. Inténtalo de nuevo más tarde. Sigues con la sesión abierta.'])
  estado.cierreOk = true
  await pulsar('.mi-cuenta-acciones button.boton-marino'); await esperar(900)
  // Desde un área privada, al quedar sin sesión el guardia de rutas pide ingresar de nuevo (comportamiento del módulo 1).
  comprobar('con el servidor respondiendo, cierra la sesión y la zona privada pide ingresar', [await ruta(), await existe('.usuario-sesion')], ['/ingresar', false])

  titulo('Mi cuenta: acceso y estados')
  await irA('/cliente/cuenta')
  comprobar('sin sesión, Mi cuenta lleva a Ingresar', await ruta(), '/ingresar')
  estado.sesion = usuarioDe('GERENTE', 'Rosa María Quispe Mamani')
  await irA('/cliente/cuenta')
  comprobar('un gerente no entra a Mi cuenta: va a su área', await ruta(), '/gerente')
  comprobar('el gerente ve su identidad sin enlace a Mi cuenta', [await texto('.identidad-nombre'), await existe('a.identidad')], ['Rosa María Quispe Mamani', false])
  estado.sesion = usuarioDe('EMPRESARIO', 'Comercial Sur S.A.C.')
  estado.rol = 'EMPRESARIO'
  estado.persona = { tipoDocumento: 'RUC', numeroDocumento: '20123456789', nombreCompleto: 'Comercial Sur S.A.C.', telefono: '987654324', telefonoAuxiliar: null, correo: 'ventas@comercialsur.pe' }
  await irA('/cliente/cuenta')
  comprobar('empresario: documento RUC, rol e iniciales', [await texto('.perfil-tarjeta p'), await texto('.avatar-grande')], ['RUC 20123456789 · usuario mariarojas · cuenta EMPRESARIO activa desde 01/03/2026', 'CS'])
} finally {
  await navegador.cerrar()
}
terminar()
