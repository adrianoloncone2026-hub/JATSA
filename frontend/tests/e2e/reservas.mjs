// Pruebas de las pantallas de reservas del cliente (módulo 4) en un navegador real: Nueva reserva, Reserva
// registrada, Mis reservas y Mi panel. Usa el arnés común: la API está interceptada (sin PHP ni base de datos)
// y algunas respuestas se retrasan a propósito para reproducir carreras asíncronas.
//
// Uso:  1) en frontend/:  npm run dev      2) desde C:\Jatsa:  node frontend/tests/e2e/reservas.mjs
import { crearInforme, esperar, iniciarNavegador } from './arnes.mjs'

const RETRASO_MS = 1500
const exito = (datos, estado = 200, demoraMs = 0) => ({ estado, cuerpo: { data: datos }, demoraMs })
const error = (estado, codigo, mensaje, campos) => ({ estado, cuerpo: { error: { codigo, mensaje, ...(campos ? { campos } : {}) } } })

const SUCURSALES = {
  MOQ001: { codigo: 'MOQ001', nombre: 'Agencia Moquegua', departamento: 'Moquegua', provincia: 'Mariscal Nieto', distrito: 'Moquegua', direccion: 'Jr. Ancash 325', telefonos: [] },
  AQP001: { codigo: 'AQP001', nombre: 'Agencia Arequipa Centro', departamento: 'Arequipa', provincia: 'Arequipa', distrito: 'Cercado', direccion: 'Av. Ejército 512', telefonos: [] },
  LIM001: { codigo: 'LIM001', nombre: 'Agencia Lima Centro', departamento: 'Lima', provincia: 'Lima', distrito: 'Cercado de Lima', direccion: 'Av. Grau 450', telefonos: [] },
  ILO001: { codigo: 'ILO001', nombre: 'Agencia Ilo', departamento: 'Moquegua', provincia: 'Ilo', distrito: 'Ilo', direccion: 'Av. Costanera 120', telefonos: [] },
}
const tarifa = (tipo, maximo, precio) => ({ tipo, pesoMaximoKg: maximo, disponible: precio !== null, precioPorBulto: precio, tiempoHoras: precio ? 4 : null, precioBase: null, precioPorKm: null, distanciaKm: null })
const TARIFAS_MOQ_AQP = [tarifa('SOBRE', 1, '9.15'), tarifa('PAQUETE', 30, '14.60'), tarifa('CARGA', 300, null)]
const AVISO_SIN_TARIFAS = 'No hay tarifas vigentes entre estas sucursales, así que no se puede reservar esta ruta. Elige otro origen o destino.'
const SIN_TARIFAS = [tarifa('SOBRE', 1, null), tarifa('PAQUETE', 30, null), tarifa('CARGA', 300, null)]

const reserva = (codigo, estadoReserva, fecha, destinatario, origen, destino, contenido = 'Documentos y ropa', codigoSeguimiento = null) => ({
  codigo, fecha, fechaHora: `${fecha} 09:15:00`, canal: 'WEB', contenido, estado: estadoReserva, destinatario,
  origen: { codigo: origen, nombre: SUCURSALES[origen].nombre, direccion: SUCURSALES[origen].direccion, departamento: SUCURSALES[origen].departamento },
  destino: { codigo: destino, nombre: SUCURSALES[destino].nombre, direccion: SUCURSALES[destino].direccion, departamento: SUCURSALES[destino].departamento },
  codigoSeguimiento,
  venceEl: estadoReserva === 'PENDIENTE' ? '2026-11-03 09:15:00' : null, plazoCumplido: false,
})
const reservasIniciales = () => [
  reserva('R000000006', 'PENDIENTE', '2026-10-31', 'Luis Alberto Flores Condori', 'MOQ001', 'AQP001'),
  reserva('R000000005', 'VENCIDA', '2026-10-20', 'Luis Alberto Flores Condori', 'ILO001', 'MOQ001', 'Cartas'),
  reserva('R000000003', 'CANCELADA', '2026-10-12', 'Luis Alberto Flores Condori', 'MOQ001', 'AQP001', 'Libros'),
  reserva('R000000001', 'FORMALIZADA', '2026-10-10', 'Luis Alberto Flores Condori', 'MOQ001', 'AQP001', 'Documentos y ropa', 'EN7K4Q2M9X1B'),
]

const PERSONAS = { 'DNI:70234561': 'Luis Alberto Flores Condori', 'DNI:45781236': 'Rosa María Quispe Mamani', 'RUC:20123456789': 'Comercial Sur S.A.C.' }
const usuario = (rol, nombreCompleto) => ({ id: 4, nombreUsuario: 'mrojas', nombreCompleto, rol, sucursal: null })

// Estado de la «base de datos» simulada.
const sim = {
  sesion: usuario('CLIENTE', 'María Elena Rojas Ccori'),
  reservas: reservasIniciales(),
  crear: null, // función que sustituye la respuesta del POST
  cancelar: null,
  fallaDestinatario: false,
  fallaListado: false,
}

const { comprobar, titulo, terminar } = crearInforme()
const navegador = await iniciarNavegador(9343, ({ metodo, ruta, url, cuerpo }) => {
  const q = (clave) => url.searchParams.get(clave)
  if (ruta === '/api/auth/yo') return sim.sesion ? exito({ usuario: sim.sesion }) : error(401, 'no_autenticado', 'Debes iniciar sesión.')
  if (ruta === '/api/cuenta') return exito({ persona: { tipoDocumento: 'DNI', numeroDocumento: '71889345', nombreCompleto: 'María Elena Rojas Ccori', telefono: '987654323', telefonoAuxiliar: null, correo: null }, usuario: { nombreUsuario: 'mrojas', rol: 'CLIENTE', activo: true, fechaCreacion: '2026-03-01' } })
  if (ruta === '/api/agencias') return exito({ agencias: Object.values(SUCURSALES) })

  if (ruta === '/api/tarifas') {
    const clave = `${q('origen')}>${q('destino')}`
    // La ruta Moquegua → Arequipa responde con retraso cuando el origen es MOQ001; el resto sale enseguida.
    return exito({ origen: { codigo: q('origen'), nombre: '' }, destino: { codigo: q('destino'), nombre: '' }, tarifas: clave === 'MOQ001>AQP001' ? TARIFAS_MOQ_AQP : SIN_TARIFAS }, 200, clave === 'MOQ001>AQP001' ? RETRASO_MS * (sim.tarifasLentas ? 1 : 0) : 0)
  }

  if (ruta === '/api/reservas/destinatario') {
    if (sim.fallaDestinatario) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    const clave = `${q('tipoDocumento')}:${q('numeroDocumento')}`
    if (clave === 'DNI:71889345') return exito({ existe: true, nombreCompleto: 'María Elena Rojas Ccori', esUsted: true })
    // El documento 70234561 responde con retraso: sirve para probar que una respuesta vieja no pisa a la nueva.
    if (PERSONAS[clave]) return exito({ existe: true, nombreCompleto: PERSONAS[clave], esUsted: false }, 200, clave === 'DNI:70234561' && sim.destinatarioLento ? RETRASO_MS : 0)
    return exito({ existe: false })
  }

  if (ruta === '/api/reservas' && metodo === 'GET') {
    if (sim.fallaListado) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    const estado = q('estado')
    // El filtro «PENDIENTE» responde con retraso cuando se pide: prueba de filtros que se pisan.
    return exito({ reservas: sim.reservas.filter((r) => !estado || r.estado === estado) }, 200, estado === 'PENDIENTE' && sim.pendientesLentas ? RETRASO_MS : 0)
  }
  if (ruta === '/api/reservas' && metodo === 'POST') {
    if (sim.crear) return sim.crear(cuerpo)
    const nueva = reserva('R000000007', 'PENDIENTE', '2026-10-31', PERSONAS[`${cuerpo.tipoDocumento}:${cuerpo.numeroDocumento}`] ?? cuerpo.nombreDestinatario, cuerpo.sucursalOrigen, cuerpo.sucursalDestino, cuerpo.contenido)
    sim.reservas.unshift(nueva)
    return exito({ reserva: nueva }, 201)
  }
  const cancelar = ruta.match(/^\/api\/reservas\/(R\d{9})\/cancelar$/)
  if (cancelar && metodo === 'POST') {
    if (sim.cancelar) return sim.cancelar(cancelar[1])
    const r = sim.reservas.find((x) => x.codigo === cancelar[1])
    r.estado = 'CANCELADA'
    return exito({ reserva: r })
  }
  const una = ruta.match(/^\/api\/reservas\/(R\d{9})$/)
  if (una) {
    const r = sim.reservas.find((x) => x.codigo === una[1])
    return r ? exito({ reserva: r }) : error(404, 'reserva_no_encontrada', 'No se encontró la reserva.')
  }
  return null
})
const { irA, escribir, pulsar, texto, valor, existe, ruta, enfocado, evaluar, llamadas } = navegador
const llamadasA = (metodo, rutaApi) => llamadas.filter((l) => l.metodo === metodo && l.ruta === rutaApi)
const ENVIAR = '.formulario-reserva button[type=submit]'
const textosDeError = () => evaluar(`[...document.querySelectorAll('.formulario-reserva .campo-error')].map(e => e.innerText).join(' | ')`)
const filasTarifas = () => evaluar(`[...document.querySelectorAll('.panel-precio .tabla-tarifas tbody tr')].map(r => r.innerText.replace(/\\s+/g,' ')).join(' || ')`)
const codigosEnLista = () => evaluar(`[...document.querySelectorAll('.tabla-reservas tbody th')].map(e => e.innerText.trim()).join(',')`)
const CUERPO_RESERVA = { tipoDocumento: 'DNI', numeroDocumento: '70234561', nombreDestinatario: '', telefonoDestinatario: '', sucursalOrigen: 'MOQ001', sucursalDestino: 'AQP001', contenido: 'Documentos y ropa' }

async function llenarReserva(cambios = {}) {
  const v = { numeroDocumento: '70234561', sucursalOrigen: 'MOQ001', sucursalDestino: 'AQP001', contenido: 'Documentos y ropa', ...cambios }
  for (const [id, valorCampo] of Object.entries(v)) await escribir(`#${id}`, valorCampo)
}

try {
  titulo('Nueva reserva: pantalla y remitente')
  await irA('/cliente/nueva-reserva')
  comprobar('título y subtítulo del prototipo', [await texto('h1'), await texto('.subtitulo')], ['Nueva reserva', 'El precio es referencial: se confirma al pesar cada bulto en la agencia'])
  comprobar('el remitente es la persona de la sesión y es de solo lectura', [await valor('#remitente'), await evaluar(`document.querySelector('#remitente').readOnly`)], ['María Elena Rojas Ccori · DNI 71889345', true])
  comprobar('el canal es WEB y no se puede cambiar', [await valor('#canal'), await evaluar(`document.querySelector('#canal').readOnly`)], ['WEB', true])
  comprobar('las sucursales se cargan desde la API', await evaluar(`document.querySelector('#sucursalOrigen').options.length`), 5)
  comprobar('sin ruta elegida, el panel de precio invita a elegir', await texto('.panel-precio .ayuda'), 'Elige el origen y el destino para ver los precios por tipo de envío.')

  titulo('Nueva reserva: destinatario por documento')
  await escribir('#numeroDocumento', '7023')
  await esperar(700)
  comprobar('con el documento incompleto no se consulta nada', llamadasA('GET', '/api/reservas/destinatario').length, 0)
  await escribir('#numeroDocumento', '70234561')
  await esperar(900)
  comprobar('documento válido: aparece el nombre con ✓', await texto('.estado-destinatario'), '✓ Luis Alberto Flores Condori')
  comprobar('se consultó una sola vez', llamadasA('GET', '/api/reservas/destinatario').length, 1)
  await escribir('#numeroDocumento', '7023456')
  await esperar(200)
  comprobar('al editar el documento desaparece el nombre anterior', await texto('.estado-destinatario'), '')
  await escribir('#tipoDocumento', 'RUC'); await escribir('#numeroDocumento', '20123456789')
  await esperar(900)
  comprobar('con RUC consulta por RUC', await texto('.estado-destinatario'), '✓ Comercial Sur S.A.C.')
  await escribir('#tipoDocumento', 'DNI'); await escribir('#numeroDocumento', '71889345')
  await esperar(900)
  comprobar('si el documento es el del propio remitente lo avisa', await texto('.estado-destinatario'), 'Este documento es el tuyo.')
  await escribir('#sucursalOrigen', 'MOQ001'); await escribir('#sucursalDestino', 'AQP001'); await escribir('#contenido', 'Algo')
  await pulsar(ENVIAR); await esperar(300)
  comprobar('y no deja reservar: error en el campo y sin llamar a la API', [await textosDeError(), llamadasA('POST', '/api/reservas').length], ['El destinatario no puede ser el mismo remitente.', 0])

  titulo('Nueva reserva: respuesta lenta del destinatario (carrera)')
  sim.destinatarioLento = true
  await irA('/cliente/nueva-reserva')
  await escribir('#numeroDocumento', '70234561') // respuesta lenta
  await esperar(700) // ya salió la consulta, aún no responde
  await escribir('#numeroDocumento', '45781236') // respuesta rápida de otro documento
  await esperar(800)
  comprobar('se muestra el destinatario del documento actual', await texto('.estado-destinatario'), '✓ Rosa María Quispe Mamani')
  await esperar(RETRASO_MS + 400) // llega tarde la respuesta del documento anterior
  comprobar('la respuesta tardía del documento anterior no la sobrescribe', await texto('.estado-destinatario'), '✓ Rosa María Quispe Mamani')
  sim.destinatarioLento = false

  titulo('Nueva reserva: destinatario nuevo')
  await irA('/cliente/nueva-reserva')
  comprobar('de entrada no se piden nombre ni teléfono del destinatario', await existe('#nombreDestinatario'), false)
  await escribir('#numeroDocumento', '48484848')
  await esperar(900)
  comprobar('documento desconocido: avisa y pide sus datos', [await texto('.estado-destinatario'), await existe('#nombreDestinatario'), await existe('#telefonoDestinatario')], ['Destinatario nuevo: ingresa sus datos.', true, true])
  await escribir('#sucursalOrigen', 'MOQ001'); await escribir('#sucursalDestino', 'AQP001'); await escribir('#contenido', 'Cartas')
  await pulsar(ENVIAR); await esperar(300)
  comprobar('sin esos datos no se puede reservar', [await textosDeError(), llamadasA('POST', '/api/reservas').length], ['Ingresa los nombres y apellidos del destinatario. | El teléfono tiene 9 dígitos.', 0])
  await escribir('#tipoDocumento', 'RUC'); await escribir('#numeroDocumento', '20999888777')
  await esperar(900)
  comprobar('con RUC cambia la etiqueta del nombre', await texto('label[for=nombreDestinatario]'), 'Razón social del destinatario')
  await escribir('#tipoDocumento', 'DNI'); await escribir('#numeroDocumento', '48484848')
  await esperar(900)
  await escribir('#nombreDestinatario', 'Ana Torres Díaz'); await escribir('#telefonoDestinatario', '911 222 333')
  await pulsar(ENVIAR); await esperar(900)
  comprobar('con los datos completos reserva y envía el destinatario nuevo normalizado', llamadasA('POST', '/api/reservas').at(-1)?.cuerpo, {
    tipoDocumento: 'DNI', numeroDocumento: '48484848', nombreDestinatario: 'Ana Torres Díaz', telefonoDestinatario: '911222333',
    sucursalOrigen: 'MOQ001', sucursalDestino: 'AQP001', contenido: 'Cartas',
  })

  titulo('Nueva reserva: precio referencial por ruta')
  await irA('/cliente/nueva-reserva')
  await escribir('#sucursalOrigen', 'MOQ001'); await escribir('#sucursalDestino', 'AQP001')
  await esperar(600)
  comprobar('Moquegua → Arequipa: precios de la diapositiva 12', await filasTarifas(), 'SOBRE 1 kg S/ 9.15 || PAQUETE 30 kg S/ 14.60 || CARGA 300 kg —')
  await escribir('#sucursalOrigen', 'AQP001'); await escribir('#sucursalDestino', 'MOQ001')
  await esperar(600)
  comprobar('ruta sin tarifas vigentes: guiones y aviso', [await filasTarifas(), await texto('.panel-precio .mensaje-aviso')], ['SOBRE 1 kg — || PAQUETE 30 kg — || CARGA 300 kg —', AVISO_SIN_TARIFAS])
  const antesSinTarifas = llamadasA('POST', '/api/reservas').length
  await escribir('#numeroDocumento', '70234561'); await escribir('#contenido', 'Documentos y ropa')
  comprobar('sin tarifas vigentes el botón «Reservar» queda deshabilitado', await evaluar(`document.querySelector('${ENVIAR}').disabled`), true)
  await evaluar(`document.querySelector('${ENVIAR}').form.requestSubmit()`); await esperar(300)
  comprobar('y aunque se fuerce el envío no se llama a la API', llamadasA('POST', '/api/reservas').length, antesSinTarifas)
  await escribir('#sucursalOrigen', 'MOQ001'); await escribir('#sucursalDestino', 'AQP001'); await esperar(600)
  comprobar('con una ruta con tarifa vuelve a habilitarse', await evaluar(`document.querySelector('${ENVIAR}').disabled`), false)
  await escribir('#sucursalOrigen', 'AQP001'); await escribir('#sucursalDestino', 'MOQ001'); await esperar(600)
  await escribir('#sucursalDestino', 'AQP001')
  await esperar(400)
  comprobar('origen = destino: no se piden precios', [await existe('.panel-precio .tabla-tarifas'), await texto('.panel-precio .ayuda')], [false, 'Elige el origen y el destino para ver los precios por tipo de envío.'])
  await pulsar(ENVIAR); await esperar(300)
  comprobar('y el formulario lo rechaza', (await textosDeError()).includes('El origen y el destino deben ser distintos.'), true)

  titulo('Nueva reserva: respuesta lenta de las tarifas (carrera)')
  sim.tarifasLentas = true
  await irA('/cliente/nueva-reserva')
  await escribir('#sucursalOrigen', 'MOQ001'); await escribir('#sucursalDestino', 'AQP001') // respuesta lenta
  await esperar(300)
  await escribir('#sucursalDestino', 'LIM001') // otra ruta, respuesta inmediata
  await esperar(500)
  comprobar('se muestran las tarifas de la ruta actual', await texto('.panel-precio .mensaje-aviso'), AVISO_SIN_TARIFAS)
  await esperar(RETRASO_MS + 300)
  comprobar('la respuesta tardía de la ruta anterior no pisa a la actual', await filasTarifas(), 'SOBRE 1 kg — || PAQUETE 30 kg — || CARGA 300 kg —')
  sim.tarifasLentas = false

  titulo('Nueva reserva: validaciones')
  await irA('/cliente/nueva-reserva')
  await pulsar(ENVIAR); await esperar(300)
  comprobar('vacío: documento, sucursales y contenido; sin llamar a la API', [await textosDeError(), llamadasA('POST', '/api/reservas').filter((l) => l.cuerpo?.contenido === undefined).length], ['El DNI tiene 8 dígitos. | Elige la sucursal de origen. | Elige la sucursal de destino. | Declara el contenido del paquete.', 0])
  comprobar('el foco va al primer campo con error', await enfocado(), 'numeroDocumento')
  await escribir('#contenido', 'x'.repeat(201))
  comprobar('el campo de contenido limita a 200 caracteres', await evaluar(`document.querySelector('#contenido').maxLength`), 200)

  titulo('Nueva reserva: envío correcto y respuestas del servidor')
  await irA('/cliente/nueva-reserva')
  await llenarReserva()
  await esperar(900)
  await pulsar(ENVIAR); await esperar(1200)
  comprobar('se envían solo los datos del formulario (el remitente lo pone el servidor)', llamadasA('POST', '/api/reservas').at(-1)?.cuerpo, CUERPO_RESERVA)
  comprobar('lleva a «Reserva registrada» con el código nuevo', await ruta(), '/cliente/reserva-registrada/R000000007')

  sim.crear = () => error(422, 'datos_invalidos', 'Revisa los datos de la reserva.', { sucursalOrigen: 'La sucursal de origen no está disponible.', contenido: 'Declara el contenido del paquete.' })
  await irA('/cliente/nueva-reserva'); await llenarReserva(); await esperar(900); await pulsar(ENVIAR); await esperar(700)
  comprobar('errores por campo del servidor: junto a cada campo y con el foco', [await textosDeError(), await enfocado()], ['La sucursal de origen no está disponible. | Declara el contenido del paquete.', 'sucursalOrigen'])
  sim.crear = () => error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
  await irA('/cliente/nueva-reserva'); await llenarReserva(); await esperar(900); await pulsar(ENVIAR); await esperar(700)
  comprobar('error del servidor: aviso arriba y el botón se reactiva', [await texto('.resultado.error'), await texto(ENVIAR)], ['Ocurrió un error interno. Inténtalo de nuevo más tarde.', 'Reservar'])
  sim.crear = null

  titulo('Nueva reserva: si falla la consulta del documento, el servidor decide')
  sim.fallaDestinatario = true
  await irA('/cliente/nueva-reserva')
  await escribir('#numeroDocumento', '48484848'); await esperar(900)
  comprobar('avisa que no pudo consultar y no bloquea el formulario', (await texto('.estado-destinatario')).startsWith('No se pudo consultar el documento.'), true)
  sim.crear = () => error(422, 'datos_invalidos', 'Revisa los datos de la reserva.', { nombreDestinatario: 'Ingresa los nombres y apellidos del destinatario.', telefonoDestinatario: 'El teléfono tiene 9 dígitos.' })
  await llenarReserva({ numeroDocumento: '48484848' }); await pulsar(ENVIAR); await esperar(700)
  comprobar('si el servidor pide los datos del destinatario nuevo, aparecen sus campos con el error', [await existe('#nombreDestinatario'), await textosDeError()], [true, 'Ingresa los nombres y apellidos del destinatario. | El teléfono tiene 9 dígitos.'])
  sim.crear = null; sim.fallaDestinatario = false

  titulo('Reserva registrada')
  sim.reservas = reservasIniciales()
  await irA('/cliente/reserva-registrada/R000000006')
  comprobar('pendiente: título, código y mensaje del prototipo', [await texto('h1'), await texto('.codigo-reserva'), await texto('.mensaje-confirmacion')],
    ['Reserva registrada', 'R000000006', 'Lleva tu paquete a Agencia Moquegua (Jr. Ancash 325) y muestra este código. Si no lo llevas, la reserva vence. Vence el 03/11/2026 09:15 (72 horas desde que la hiciste).'])
  comprobar('botones: Mis reservas, Ir al panel y Horarios', await evaluar(`[...document.querySelectorAll('.acciones-confirmacion a')].map(a => a.innerText + '→' + a.getAttribute('href')).join(' | ')`), 'Mis reservas→/cliente/reservas | Ir al panel→/cliente | Horarios→/salidas')
  await irA('/cliente/reserva-registrada/R000000001')
  comprobar('formalizada: título y código de seguimiento', [await texto('h1'), await texto('.mensaje-confirmacion')], ['Reserva formalizada', 'Tu paquete ya fue registrado en la agencia. Su código de seguimiento es EN7K4Q2M9X1B.'])
  await irA('/cliente/reserva-registrada/R000000003')
  comprobar('cancelada', [await texto('h1'), await texto('.mensaje-confirmacion')], ['Reserva cancelada', 'Cancelaste esta reserva.'])
  await irA('/cliente/reserva-registrada/R000000005')
  comprobar('vencida', [await texto('h1'), await texto('.mensaje-confirmacion')], ['Reserva vencida', 'Esta reserva venció: pasaron 72 horas y el paquete no llegó a la agencia.'])
  sim.reservas.push({ ...reserva('R000000007', 'PENDIENTE', '2026-10-25', 'Luis Alberto Flores Condori', 'MOQ001', 'AQP001'), venceEl: '2026-10-28 09:15:00', plazoCumplido: true })
  await irA('/cliente/reserva-registrada/R000000007')
  comprobar('pendiente con el plazo cumplido: se avisa que ya no sirve', await texto('.mensaje-confirmacion'), 'Pasaron las 72 horas de vigencia: esta reserva está por marcarse como vencida y ya no se puede usar ni cancelar.')
  await irA('/cliente/reservas')
  comprobar('y en «Mis reservas» no ofrece cancelar', await evaluar(`(() => { const f = [...document.querySelectorAll('.tabla-reservas tbody tr')].find(t => t.innerText.includes('R000000007')); return f.querySelector('.celda-accion').innerText.trim() })()`), 'Plazo cumplido')
  sim.reservas = sim.reservas.filter((r) => r.codigo !== 'R000000007')
  await irA('/cliente/reserva-registrada/R000000999')
  comprobar('un código que no es de la persona: «No encontramos esa reserva»', [await texto('h1'), await texto('.confirmacion p')], ['No encontramos esa reserva', 'No se encontró la reserva.'])

  titulo('Mis reservas')
  sim.reservas = reservasIniciales()
  await irA('/cliente/reservas')
  comprobar('lista las reservas con código, fecha, canal, destinatario y estado', await evaluar(`[...document.querySelectorAll('.tabla-reservas tbody tr')][0].innerText.replace(/\\s+/g,' ').trim()`), 'R000000006 31/10/2026 WEB Luis Alberto Flores Condori → Agencia Moquegua a Agencia Arequipa Centro PENDIENTE Cancelar reserva')
  comprobar('de la más reciente a la más antigua', await codigosEnLista(), 'R000000006,R000000005,R000000003,R000000001')
  comprobar('formalizada: «Ver envío» lleva al rastreo de su envío', await evaluar(`[...document.querySelectorAll('.tabla-reservas tbody tr')][3].querySelector('a.boton').getAttribute('href')`), '/rastreo?codigo=EN7K4Q2M9X1B')
  comprobar('cancelada y vencida ofrecen «Ver reserva»', await evaluar(`[1,2].map(i => [...document.querySelectorAll('.tabla-reservas tbody tr')][i].querySelector('a.boton').innerText).join(' | ')`), 'Ver reserva | Ver reserva')
  comprobar('el filtro «Todas» está activo', await evaluar(`document.querySelector('.filtro[aria-pressed=true]').innerText`), 'Todas')

  titulo('Mis reservas: filtros')
  await pulsar('.filtros button:nth-child(3)'); await esperar(500)
  comprobar('«Formalizadas» pide solo esas al servidor y las muestra', [llamadasA('GET', '/api/reservas').at(-1)?.ruta, await codigosEnLista()], ['/api/reservas', 'R000000001'])
  comprobar('el filtro pasa por la consulta (?estado=FORMALIZADA)', await evaluar(`performance.getEntriesByType('resource').filter(e => e.name.includes('/api/reservas?estado=FORMALIZADA')).length > 0`), true)
  await pulsar('.filtros button:nth-child(5)'); await esperar(500)
  comprobar('«Vencidas»', await codigosEnLista(), 'R000000005')
  sim.reservas = []
  await pulsar('.filtros button:nth-child(4)'); await esperar(500)
  comprobar('un filtro sin resultados muestra su mensaje y el botón para reservar', [await texto('.estado-vacio p'), await texto('.estado-vacio a')], ['No tienes reservas canceladas.', 'Hacer una reserva'])
  sim.reservas = reservasIniciales()

  titulo('Mis reservas: filtros que se pisan (carrera)')
  sim.pendientesLentas = true
  await irA('/cliente/reservas')
  await pulsar('.filtros button:nth-child(2)') // «Pendientes»: respuesta lenta
  await esperar(300)
  await pulsar('.filtros button:nth-child(3)') // «Formalizadas»: respuesta inmediata
  await esperar(500)
  comprobar('queda la lista del filtro actual', [await codigosEnLista(), await evaluar(`document.querySelector('.filtro[aria-pressed=true]').innerText`)], ['R000000001', 'Formalizadas'])
  await esperar(RETRASO_MS + 400)
  comprobar('la respuesta tardía de «Pendientes» no la sobrescribe', await codigosEnLista(), 'R000000001')
  sim.pendientesLentas = false

  titulo('Mis reservas: cancelar')
  sim.reservas = reservasIniciales()
  await irA('/cliente/reservas')
  await pulsar('.tabla-reservas tbody tr:nth-child(1) .celda-accion button'); await esperar(200)
  comprobar('pide confirmación antes de cancelar', [await texto('.confirmar-cancelacion span'), llamadasA('POST', '/api/reservas/R000000006/cancelar').length], ['¿Cancelar R000000006?', 0])
  await pulsar('.confirmar-cancelacion button:nth-child(3)'); await esperar(200)
  comprobar('«No» cierra la confirmación sin cancelar', [await existe('.confirmar-cancelacion'), llamadasA('POST', '/api/reservas/R000000006/cancelar').length], [false, 0])
  await pulsar('.tabla-reservas tbody tr:nth-child(1) .celda-accion button'); await esperar(200)
  await pulsar('.confirmar-cancelacion button:nth-child(2)'); await esperar(700)
  comprobar('«Sí, cancelar»: aviso y la reserva pasa a CANCELADA', [await texto('.resultado.ok'), await evaluar(`[...document.querySelectorAll('.tabla-reservas tbody tr')][0].querySelector('.insignia').innerText`), await evaluar(`document.querySelectorAll('.tabla-reservas tbody tr')[0].querySelectorAll('.celda-accion button').length`)], ['Reserva R000000006 cancelada.', 'CANCELADA', 0])

  sim.reservas = reservasIniciales()
  await irA('/cliente/reservas')
  await pulsar('.filtros button:nth-child(2)'); await esperar(500)
  await pulsar('.tabla-reservas tbody tr:nth-child(1) .celda-accion button'); await esperar(200)
  await pulsar('.confirmar-cancelacion button:nth-child(2)'); await esperar(900)
  comprobar('en «Pendientes», la cancelada sale de la lista y queda el mensaje de vacío', [await existe('.tabla-reservas'), await texto('.estado-vacio p')], [false, 'No tienes reservas pendientes.'])

  sim.reservas = reservasIniciales()
  sim.cancelar = () => error(409, 'reserva_no_cancelable', 'Solo se pueden cancelar las reservas pendientes.')
  await irA('/cliente/reservas')
  await pulsar('.tabla-reservas tbody tr:nth-child(1) .celda-accion button'); await esperar(200)
  await pulsar('.confirmar-cancelacion button:nth-child(2)'); await esperar(900)
  comprobar('si el servidor dice que ya no se puede (409): lo informa y recarga la lista', [await texto('.resultado.error'), await existe('.confirmar-cancelacion')], ['Solo se pueden cancelar las reservas pendientes.', false])
  sim.cancelar = null

  titulo('Mis reservas: errores')
  sim.fallaListado = true
  await irA('/cliente/reservas')
  comprobar('si falla la consulta: aviso con «Reintentar»', [await texto('.resultado.error strong'), await existe('.resultado.error button')], ['No se pudieron cargar tus reservas.', true])
  sim.fallaListado = false
  await pulsar('.resultado.error button'); await esperar(600)
  comprobar('«Reintentar» recupera la lista', await codigosEnLista(), 'R000000006,R000000005,R000000003,R000000001')

  titulo('Mi panel')
  sim.reservas = reservasIniciales()
  await irA('/cliente')
  comprobar('saludo, contador y accesos', [await texto('h1'), await texto('.indicador'), await evaluar(`[...document.querySelectorAll('.acciones-panel a')].map(a => a.innerText + '→' + a.getAttribute('href')).join(' | ')`)],
    ['Hola, María Elena', '1 Reservas pendientes', 'Nueva reserva→/cliente/nueva-reserva | Rastrear un envío→/rastreo | Ver salidas→/salidas'])
  comprobar('tarjeta de la reserva pendiente', [await texto('.tarjeta-reserva h3'), await texto('.tarjeta-reserva .insignia'), await texto('.tarjeta-reserva p')], ['R000000006', 'PENDIENTE', 'Para Luis Alberto Flores Condori · Lleva el paquete a Agencia Moquegua.'])
  comprobar('«Ver reserva» y «Horario»', await evaluar(`[...document.querySelectorAll('.acciones-tarjeta a')].map(a => a.innerText + '→' + a.getAttribute('href')).join(' | ')`), 'Ver reserva→/cliente/reserva-registrada/R000000006 | Horario→/salidas')
  sim.reservas = [...reservasIniciales(), reserva('R000000007', 'PENDIENTE', '2026-10-30', 'Rosa María Quispe Mamani', 'AQP001', 'LIM001')]
  await irA('/cliente')
  comprobar('con varias pendientes: contador y enlace a todas', [await texto('.indicador'), await texto('.tarjeta-reserva .ayuda')], ['2 Reservas pendientes', 'Tienes 2 reservas pendientes. Ver todas'])
  sim.reservas = reservasIniciales().filter((r) => r.estado !== 'PENDIENTE')
  await irA('/cliente')
  comprobar('sin pendientes: estado vacío con acceso a reservar', [await texto('.indicador'), await texto('.estado-vacio p')], ['0 Reservas pendientes', 'No tienes reservas pendientes.'])
  sim.fallaListado = true
  await irA('/cliente')
  comprobar('si falla: aviso con «Reintentar», y los accesos siguen disponibles', [await texto('.resultado.error strong'), await existe('.acciones-panel a')], ['No se pudieron cargar tus reservas.', true])
  sim.fallaListado = false
  sim.sesion = usuario('EMPRESARIO', 'Comercial Sur S.A.C.')
  await irA('/cliente')
  comprobar('un empresario ve su razón social completa', await texto('h1'), 'Hola, Comercial Sur S.A.C.')

  titulo('Acceso')
  sim.sesion = null
  await irA('/cliente/nueva-reserva')
  comprobar('sin sesión, Nueva reserva lleva a Ingresar', await ruta(), '/ingresar')
  await irA('/cliente/reservas')
  comprobar('sin sesión, Mis reservas lleva a Ingresar', await ruta(), '/ingresar')
  sim.sesion = usuario('GERENTE', 'Rosa María Quispe Mamani')
  await irA('/cliente/nueva-reserva')
  comprobar('un gerente no entra a Nueva reserva: va a su área', await ruta(), '/gerente')
} finally {
  await navegador.cerrar()
}
terminar()
