// Pruebas de «Cambiar contraseña» (autorización del PR #11): todos los roles cambian SOLO su propia contraseña. Para el personal
// (gerente, administrador y transportista) es una pantalla propia, sin datos personales ni funciones de cliente. La API está
// interceptada (sin PHP ni base de datos). La regresión de «Mi cuenta» del cliente la cubre cuenta.mjs.
//
// Uso:  1) en frontend/:  npm run dev      2) desde C:\Jatsa:  node frontend/tests/e2e/contrasena.mjs
import { crearInforme, esperar, iniciarNavegador } from './arnes.mjs'

const exito = (datos, estado = 200) => ({ estado, cuerpo: { data: datos } })
const error = (estado, codigo, mensaje, campos) => ({ estado, cuerpo: { error: { codigo, mensaje, ...(campos ? { campos } : {}) } } })
const SUC = { id: 3, codigo: 'MOQ001', nombre: 'Agencia Moquegua' }
const USUARIOS = {
  GERENTE: { id: 1, nombreUsuario: 'rquispe', nombreCompleto: 'Rosa María Quispe Mamani', rol: 'GERENTE', sucursal: null },
  ADMINISTRADOR: { id: 2, nombreUsuario: 'lflores', nombreCompleto: 'Luis Alberto Flores Condori', rol: 'ADMINISTRADOR', sucursal: SUC },
  TRANSPORTISTA: { id: 6, nombreUsuario: 'pmamani', nombreCompleto: 'Pedro Mamani Huanca', rol: 'TRANSPORTISTA', sucursal: { id: 2, codigo: 'AQP001', nombre: 'Agencia Arequipa Centro' } },
  CLIENTE: { id: 4, nombreUsuario: 'mrojas', nombreCompleto: 'María Elena Rojas Ccori', rol: 'CLIENTE', sucursal: null },
}
const sim = { usuario: USUARIOS.GERENTE, claveActual: 'Actual#2026', falla: false }

const { comprobar, titulo, terminar } = crearInforme()
const navegador = await iniciarNavegador(9350, ({ metodo, ruta, cuerpo }) => {
  if (ruta === '/api/auth/yo') return sim.usuario ? exito({ usuario: sim.usuario }) : error(401, 'no_autenticado', 'Debes iniciar sesión.')
  if (ruta === '/api/cuenta/contrasena' && metodo === 'POST') {
    if (sim.falla) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    if (cuerpo.contrasenaActual !== sim.claveActual) return error(422, 'contrasena_incorrecta', 'La contraseña actual no es correcta.', { contrasenaActual: 'La contraseña actual no es correcta.' })
    sim.claveActual = cuerpo.contrasenaNueva
    return exito({ contrasenaCambiada: true })
  }
  // El personal NO usa los datos personales: estas rutas solo existen para cliente y empresario.
  if (ruta === '/api/cuenta') {
    return sim.usuario?.rol === 'CLIENTE'
      ? exito({ persona: { tipoDocumento: 'DNI', numeroDocumento: '71889345', nombreCompleto: 'María Elena Rojas Ccori', telefono: '987654323', telefonoAuxiliar: null, correo: null }, usuario: { nombreUsuario: 'mrojas', rol: 'CLIENTE', activo: true, fechaCreacion: '2026-03-01' } })
      : error(403, 'acceso_denegado', 'No tienes permiso para realizar esta acción.')
  }
  return null
})
const { escribir, pulsar, texto, existe, ruta, evaluar, llamadas } = navegador

async function estable(minimoMs = 250, limiteMs = 10000) {
  await esperar(minimoMs)
  const inicio = Date.now()
  let anterior = null
  while (Date.now() - inicio < limiteMs) {
    const estado = await evaluar(`JSON.stringify({ cargando: !!document.querySelector('.cargando'), texto: document.body.innerText })`)
    const lectura = estado ? JSON.parse(estado) : null
    if (lectura && !lectura.cargando && lectura.texto.trim().length > 0 && estado === anterior) return
    anterior = estado
    await esperar(150)
  }
  throw new Error('La pantalla no se estabilizó a tiempo')
}
const irA = async (rutaNueva) => { await navegador.irA(rutaNueva, 100); await estable(300) }
const envios = () => llamadas.filter((l) => l.metodo === 'POST' && l.ruta === '/api/cuenta/contrasena')
const enlace = (selector) => evaluar(`document.querySelector(${JSON.stringify(selector)})?.getAttribute('href') ?? null`)

try {
  for (const [rol, base, inicio] of [['GERENTE', '/gerente', '/gerente'], ['ADMINISTRADOR', '/administrador', '/administrador'], ['TRANSPORTISTA', '/transportista', '/transportista']]) {
    titulo(`${rol}: Cambiar contraseña`)
    sim.usuario = USUARIOS[rol]
    sim.claveActual = 'Actual#2026'
    sim.falla = false
    llamadas.length = 0
    await irA(inicio)
    comprobar('desde su nombre en la cabecera se llega a «Cambiar contraseña»', [await enlace('.usuario-sesion a.identidad'), await evaluar(`document.querySelector('.usuario-sesion a.identidad')?.getAttribute('aria-label')`)], [`${base}/contrasena`, `Cambiar contraseña, ${USUARIOS[rol].nombreCompleto}`])
    await pulsar('.usuario-sesion a.identidad'); await estable()
    comprobar('la pantalla es solo de contraseña: sin datos personales, teléfonos, correo ni funciones de cliente', [await texto('h1'), await existe('#contrasena-actual'), await existe('input[name=telefono]'), await existe('input[name=correo]'), (await evaluar('document.body.innerText')).match(/Mis pagos|Historial|Mis reservas|Nueva reserva|Teléfono|Correo/) === null],
      ['Cambiar contraseña', true, false, false, true])
    comprobar('no consulta ni edita los datos personales (GET/PATCH /api/cuenta)', llamadas.filter((l) => l.ruta === '/api/cuenta').length, 0)
    comprobar('los tres campos son de tipo password', await evaluar(`['#contrasena-actual', '#contrasena-nueva', '#contrasena-confirmacion'].map(s => document.querySelector(s).type).join(',')`), 'password,password,password')

    await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await estable()
    comprobar('vacío: errores en pantalla y no se llama a la API', [await texto('#contrasena-actual-error'), envios().length], ['Ingresa tu contraseña actual.', 0])
    await escribir('#contrasena-actual', 'Actual#2026'); await escribir('#contrasena-nueva', 'corta'); await escribir('#contrasena-confirmacion', 'otra'); await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await estable()
    comprobar('nueva demasiado corta y confirmación distinta', [await texto('#contrasena-nueva-error'), await texto('#contrasena-confirmacion-error'), envios().length], ['La contraseña debe tener al menos 8 caracteres.', 'Las contraseñas no coinciden.', 0])

    await escribir('#contrasena-actual', 'Equivocada#1'); await escribir('#contrasena-nueva', 'Nueva#2026x'); await escribir('#contrasena-confirmacion', 'Nueva#2026x'); await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await estable()
    comprobar('contraseña actual incorrecta: el servidor lo marca en su campo', [await texto('#contrasena-actual-error'), envios().length], ['La contraseña actual no es correcta.', 1])
    sim.falla = true
    await escribir('#contrasena-actual', 'Actual#2026'); await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await estable()
    comprobar('error del servidor: aviso general', await texto('.resultado.error'), 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    sim.falla = false
    await pulsar('form[aria-label="Cambiar contraseña"] button[type=submit]'); await estable()
    comprobar('cambio correcto: aviso, formulario vacío y cuerpo enviado solo con las dos contraseñas', [await texto('.resultado.ok'), await evaluar(`document.querySelector('#contrasena-actual').value + '|' + document.querySelector('#contrasena-nueva').value`), envios().at(-1).cuerpo],
      ['Contraseña actualizada. Las demás sesiones abiertas de esta cuenta se cerraron.', '|', { contrasenaActual: 'Actual#2026', contrasenaNueva: 'Nueva#2026x' }])
    comprobar('sigue en la misma pantalla con la sesión abierta', await ruta(), `${base}/contrasena`)
    await pulsar('p > a.boton'); await estable()
    comprobar('«Volver» regresa a su área', await ruta(), inicio)
  }

  titulo('Acceso por rol')
  sim.usuario = USUARIOS.CLIENTE
  await irA('/gerente/contrasena')
  comprobar('un cliente no entra a la pantalla de contraseña del personal (lo manda a su panel)', await ruta(), '/cliente')
  sim.usuario = USUARIOS.ADMINISTRADOR
  await irA('/transportista/contrasena')
  comprobar('un administrador no entra a la ruta de otro rol', await ruta(), '/administrador')
  sim.usuario = null
  await irA('/gerente/contrasena')
  comprobar('sin sesión pide ingresar', (await ruta()).startsWith('/ingresar'), true)

  titulo('Regresión: el cliente conserva «Mi cuenta» (la cabecera sigue llevando a /cliente/cuenta)')
  sim.usuario = USUARIOS.CLIENTE
  await irA('/cliente')
  comprobar('su nombre sigue llevando a Mi cuenta', await enlace('.usuario-sesion a.identidad'), '/cliente/cuenta')
  await pulsar('.usuario-sesion a.identidad'); await estable()
  comprobar('Mi cuenta muestra sus datos y el botón de cambiar contraseña', [await texto('h1'), (await evaluar('document.body.innerText')).includes('Cambiar contraseña')], ['Mi cuenta', true])
} finally {
  await navegador.cerrar()
}
terminar()