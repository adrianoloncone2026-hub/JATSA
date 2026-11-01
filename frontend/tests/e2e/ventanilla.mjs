// Pruebas de las pantallas del Administrador del módulo 5 en un navegador real: Ventanilla, Cliente sin reserva,
// Formalizar envío y Envío registrado (con su etiqueta). La API está interceptada (sin PHP ni base de datos) y algunas
// respuestas se retrasan a propósito para reproducir carreras asíncronas.
//
// Uso:  1) en frontend/:  npm run dev      2) desde C:\Jatsa:  node frontend/tests/e2e/ventanilla.mjs
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
const ADMIN = { id: 2, nombreUsuario: 'lflores', nombreCompleto: 'Luis Flores', rol: 'ADMINISTRADOR', sucursal: { id: 3, codigo: 'MOQ001', nombre: 'Agencia Moquegua' } }

const persona = (tipoDocumento, numeroDocumento, nombreCompleto) => ({ tipoDocumento, numeroDocumento, nombreCompleto })
const MARIA = persona('DNI', '71889345', 'María Elena Rojas Ccori')
const LUIS = persona('DNI', '70234561', 'Luis Alberto Flores Condori')
const sucursal = (c) => ({ codigo: c, nombre: SUCURSALES[c].nombre, direccion: SUCURSALES[c].direccion, departamento: SUCURSALES[c].departamento })
const reserva = (codigo, estado, extra = {}) => ({
  codigo, fecha: '2026-10-31', fechaHora: '2026-10-31 09:15:00', canal: 'WEB', contenido: 'Documentos y ropa', estado,
  remitente: MARIA, destinatario: LUIS, origen: sucursal('MOQ001'), destino: sucursal('AQP001'), codigoSeguimiento: null,
  venceEl: estado === 'PENDIENTE' ? '2026-11-03 09:15:00' : null, plazoCumplido: false, ...extra,
})

// Tarifas de Moquegua → Arequipa: SOBRE 9.15 y PAQUETE 14.60 (sin CARGA), como en la diapositiva 19.
const COSTOS = { SOBRE: '9.15', PAQUETE: '14.60' }
const tipoPorPeso = (kg) => (kg <= 1 ? 'SOBRE' : kg <= 30 ? 'PAQUETE' : kg <= 300 ? 'CARGA' : null)
const kilos = (c) => `${Math.floor(c / 100)}.${String(c % 100).padStart(2, '0')}`

function cotizar(bultos) {
  let centimos = 0
  let centesimas = 0
  let valido = bultos.length > 0
  const filas = bultos.map((b, i) => {
    const peso = Number(String(b.pesoKg).replace(',', '.'))
    const tipo = tipoPorPeso(peso)
    const costo = COSTOS[tipo] ?? null
    const fila = { numero: i + 1, descripcion: b.descripcion, pesoKg: kilos(Math.round(peso * 100)), tipo, costo, error: null, errorDescripcion: null }
    if (tipo === null) fila.error = 'El peso máximo por bulto es 300 kg.'
    else if (costo === null) fila.error = `${tipo}: sin ruta vigente MOQ→AQP`
    if (fila.error) valido = false
    else {
      centimos += Math.round(Number(costo) * 100)
      centesimas += Math.round(peso * 100)
    }
    return fila
  })
  return { reserva: { codigo: '', origen: sucursal('MOQ001'), destino: sucursal('AQP001') }, distanciaKm: '230', bultos: filas, cantidadBultos: filas.length, pesoTotalKg: kilos(centesimas), importe: kilos(centimos), valido }
}

const sim = {
  sesion: ADMIN,
  reservas: {},
  pendientes: [],
  envios: {},
  fallaPendientes: false,
  buscarLento: null, // código cuya búsqueda responde con retraso
  cotizarLento: false,
  formalizar: null, // función que sustituye la respuesta del POST /api/envios
  siguienteReserva: 30,
  crearReserva: null,
}

function reiniciar() {
  sim.reservas = {
    R000000006: reserva('R000000006', 'PENDIENTE'),
    R000000007: reserva('R000000007', 'PENDIENTE', { remitente: persona('RUC', '20123456789', 'Comercial Sur S.A.C.'), destino: sucursal('ILO001') }),
    R000000001: reserva('R000000001', 'FORMALIZADA', { codigoSeguimiento: 'EN7K4Q2M9X1B' }),
    R000000003: reserva('R000000003', 'CANCELADA'),
    R000000009: reserva('R000000009', 'PENDIENTE', { fechaHora: '2026-10-28 09:15:00', venceEl: '2026-10-31 09:15:00', plazoCumplido: true }),
  }
  sim.pendientes = ['R000000006', 'R000000007']
  sim.envios = {}
}
reiniciar()

const PERSONAS = { 'DNI:70234561': 'Luis Alberto Flores Condori', 'DNI:71889345': 'María Elena Rojas Ccori' }

const { comprobar, titulo, terminar } = crearInforme()
const navegador = await iniciarNavegador(9344, ({ metodo, ruta, url, cuerpo }) => {
  const q = (clave) => url.searchParams.get(clave)
  if (ruta === '/api/auth/yo') return sim.sesion ? exito({ usuario: sim.sesion }) : error(401, 'no_autenticado', 'Debes iniciar sesión.')
  if (ruta === '/api/agencias') return exito({ agencias: Object.values(SUCURSALES) })
  if (ruta === '/api/tarifas') {
    const con = `${q('origen')}>${q('destino')}` === 'MOQ001>AQP001'
    const t = (tipo, max, precio) => ({ tipo, pesoMaximoKg: max, disponible: precio !== null, precioPorBulto: precio, tiempoHoras: precio ? 4 : null, precioBase: null, precioPorKm: null, distanciaKm: null })
    return exito({ origen: { codigo: q('origen'), nombre: '' }, destino: { codigo: q('destino'), nombre: '' }, tarifas: con ? [t('SOBRE', 1, '9.15'), t('PAQUETE', 30, '14.60'), t('CARGA', 300, null)] : [t('SOBRE', 1, null), t('PAQUETE', 30, null), t('CARGA', 300, null)] })
  }

  if (ruta === '/api/ventanilla/personas') {
    const nombre = PERSONAS[`${q('tipoDocumento')}:${q('numeroDocumento')}`]
    return nombre ? exito({ existe: true, nombreCompleto: nombre }) : exito({ existe: false })
  }
  if (ruta === '/api/ventanilla/reservas' && metodo === 'GET') {
    if (sim.fallaPendientes) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    return exito({ reservas: sim.pendientes.map((c) => sim.reservas[c]) })
  }
  if (ruta === '/api/ventanilla/reservas' && metodo === 'POST') {
    if (sim.crearReserva) return sim.crearReserva(cuerpo)
    const codigo = `R0000000${sim.siguienteReserva++}`
    sim.reservas[codigo] = reserva(codigo, 'PENDIENTE', { canal: 'VENTANILLA', contenido: cuerpo.contenido, destino: sucursal(cuerpo.sucursalDestino) })
    return exito({ reserva: sim.reservas[codigo] }, 201)
  }
  const una = ruta.match(/^\/api\/ventanilla\/reservas\/(R\d{9})$/)
  if (una) {
    const r = sim.reservas[una[1]]
    return r ? exito({ reserva: r }, 200, sim.buscarLento === una[1] ? RETRASO_MS : 0) : error(404, 'reserva_no_encontrada', 'No se encontró la reserva en esta agencia.')
  }

  if (ruta === '/api/envios/cotizacion') {
    const r = sim.reservas[cuerpo.codigoReserva]
    if (!r) return error(404, 'reserva_no_encontrada', 'No se encontró la reserva en esta agencia.')
    // Con «cotizarLento», cotizar el bulto de 5 kg tarda: sirve para comprobar que una respuesta vieja no pisa a la actual.
    const lento = sim.cotizarLento && cuerpo.bultos.some((b) => String(b.pesoKg) === '5')
    return exito(cotizar(cuerpo.bultos), 200, lento ? RETRASO_MS : 0)
  }
  if (ruta === '/api/envios' && metodo === 'POST') {
    if (sim.formalizar) return sim.formalizar(cuerpo)
    const c = cotizar(cuerpo.bultos)
    const codigo = 'ENK7Q2M9X1BT'
    const envio = {
      codigoSeguimiento: codigo, fechaHoraRegistro: '2026-10-31 09:20:00', estado: 'REGISTRADO', tipoEntrega: cuerpo.tipoEntrega,
      direccionEntrega: cuerpo.tipoEntrega === 'DOMICILIO' ? cuerpo.direccionEntrega : null, reserva: { ...sim.reservas[cuerpo.codigoReserva], codigo: cuerpo.codigoReserva },
      bultos: c.bultos.map((b) => ({ numero: b.numero, descripcion: b.descripcion, pesoKg: b.pesoKg, tipo: b.tipo, costo: b.costo })),
      cantidadBultos: c.cantidadBultos, pesoTotalKg: c.pesoTotalKg, importe: c.importe,
    }
    sim.envios[codigo] = envio
    sim.reservas[cuerpo.codigoReserva] = { ...sim.reservas[cuerpo.codigoReserva], estado: 'FORMALIZADA', codigoSeguimiento: codigo, venceEl: null }
    sim.pendientes = sim.pendientes.filter((x) => x !== cuerpo.codigoReserva)
    return exito({ envio }, 201)
  }
  const envio = ruta.match(/^\/api\/envios\/(EN[A-Z0-9]{10})$/)
  if (envio) return sim.envios[envio[1]] ? exito({ envio: sim.envios[envio[1]] }) : error(404, 'envio_no_encontrado', 'No se encontró el envío.')
  return null
})
const { irA, escribir, pulsar, texto, valor, existe, ruta, enfocado, evaluar, llamadas } = navegador
// Con CAPTURAS=<carpeta> guarda capturas de pantalla para revisar el diseño contra el prototipo.
const captura = async (nombre, ancho = 1280, alto = 800) => { if (process.env.CAPTURAS) await navegador.captura(`${process.env.CAPTURAS}/${nombre}.png`, ancho, alto) }
const llamadasA = (metodo, rutaApi) => llamadas.filter((l) => l.metodo === metodo && l.ruta === rutaApi)
const enlaces = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(a => a.innerText.trim() + '→' + a.getAttribute('href')).join(' | ')`)
const filas = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(r => r.innerText.replace(/\\s+/g,' ').trim()).join(' || ')`)
const errores = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)} + ' .campo-error')].map(e => e.innerText.trim()).join(' | ')`)
const deshabilitado = (selector) => evaluar(`document.querySelector(${JSON.stringify(selector)}).disabled`)
const BTN_COBRAR = '.acciones-formulario .boton-azul'
const BTN_DESTINO = '.acciones-formulario .boton-marino'

// Agrega un bulto escribiendo en la fila de captura y esperando la vista previa.
async function agregarBulto(descripcion, peso) {
  await escribir('#descripcionBulto', descripcion)
  await escribir('#pesoBulto', peso)
  await esperar(700)
  await pulsar('.nuevo-bulto .boton-marino')
  await esperar(600)
}

try {
  titulo('Ventanilla: pantalla de la diapositiva 18')
  await irA('/administrador')
  comprobar('título con el nombre de la agencia', await texto('h1'), 'Ventanilla · Agencia Moquegua')
  comprobar('reservas pendientes de la agencia, con botón Formalizar', [await texto('h2'), await filas('.tabla-reservas tbody tr')],
    ['Reservas pendientes para esta agencia', 'R000000006 María Elena Rojas Ccori Agencia Arequipa Centro 03/11/2026 09:15 Formalizar || R000000007 Comercial Sur S.A.C. Agencia Ilo 03/11/2026 09:15 Formalizar'])
  comprobar('«Formalizar» lleva a la pantalla de esa reserva', (await enlaces('.tabla-reservas tbody a')).split(' | ')[0], 'Formalizar→/administrador/formalizar-envio/R000000006')
  comprobar('accesos: Cliente sin reserva, Cobrar, Despachar, Entregar y Ver salidas', await enlaces('.accesos-ventanilla a'),
    'Cliente sin reserva→/administrador/sin-reserva | Cobrar→/administrador/caja | Despachar→/administrador/despacho | Entregar→/administrador/llegadas-entregas | Ver salidas→/salidas')
  comprobar('el menú del administrador', await enlaces('nav.menu a'), 'Ventanilla→/administrador | Caja→/administrador/caja | Despacho→/administrador/despacho | Llegadas y entregas→/administrador/llegadas-entregas | Reportes→/administrador/reportes')

  await captura('18_ventanilla')
  titulo('Ventanilla: buscar por código')
  await escribir('#codigoReserva', 'R12')
  await pulsar('.buscar-reserva button')
  await esperar(300)
  comprobar('un código mal escrito se explica y no llama a la API', [await texto('.resultado.error'), llamadasA('GET', '/api/ventanilla/reservas/R12').length], ['El código de reserva es la letra R seguida de 9 dígitos (por ejemplo, R000000006).', 0])
  await escribir('#codigoReserva', ' r000000006 ')
  await pulsar('.buscar-reserva button')
  await esperar(600)
  comprobar('encuentra la reserva (acepta minúsculas y espacios) y muestra documentos', [await texto('.reserva-encontrada-cabecera'), await filas('.reserva-encontrada p')],
    ['R000000006 PENDIENTE Agencia Moquegua → Agencia Arequipa Centro',
      'Remitente: María Elena Rojas Ccori · DNI 71889345 || Destinatario: Luis Alberto Flores Condori · DNI 70234561 || Contenido: Documentos y ropa · canal WEB · 31/10/2026 09:15 || Vence el 03/11/2026 09:15.'])
  comprobar('botón «Formalizar envío» hacia su pantalla', await enlaces('.reserva-encontrada a.boton-azul'), 'Formalizar envío→/administrador/formalizar-envio/R000000006')
  await escribir('#codigoReserva', 'R000000999'); await pulsar('.buscar-reserva button'); await esperar(500)
  comprobar('reserva inexistente o de otra agencia: mensaje del servidor y sin tarjeta', [await texto('.resultado.error'), await existe('.reserva-encontrada')], ['No se encontró la reserva en esta agencia.', false])
  await escribir('#codigoReserva', 'R000000001'); await pulsar('.buscar-reserva button'); await esperar(500)
  comprobar('formalizada: no se puede formalizar y enlaza a su envío', [await existe('.reserva-encontrada a.boton-azul'), await texto('.reserva-encontrada .mensaje-aviso')], [false, 'Esta reserva ya fue formalizada. Ver el envío EN7K4Q2M9X1B'])
  await escribir('#codigoReserva', 'R000000003'); await pulsar('.buscar-reserva button'); await esperar(500)
  comprobar('cancelada: no se puede formalizar', [await existe('.reserva-encontrada a.boton-azul'), await texto('.reserva-encontrada .mensaje-aviso')], [false, 'Esta reserva fue cancelada: no se puede formalizar.'])
  await escribir('#codigoReserva', 'R000000009'); await pulsar('.buscar-reserva button'); await esperar(500)
  comprobar('pendiente con el plazo de 72 h cumplido: no se puede formalizar', [await existe('.reserva-encontrada a.boton-azul'), await texto('.reserva-encontrada .mensaje-aviso')], [false, 'Pasaron las 72 horas de vigencia: ya no se puede formalizar. Crea una nueva reserva.'])

  titulo('Ventanilla: dos búsquedas seguidas (carrera)')
  sim.buscarLento = 'R000000006'
  await irA('/administrador')
  await escribir('#codigoReserva', 'R000000006'); await pulsar('.buscar-reserva button')
  await esperar(300)
  await escribir('#codigoReserva', 'R000000007'); await pulsar('.buscar-reserva button')
  await esperar(500)
  comprobar('se muestra la última búsqueda', await texto('.reserva-encontrada-cabecera .codigo-reserva-chico'), 'R000000007')
  await esperar(RETRASO_MS + 300)
  comprobar('la respuesta tardía de la búsqueda anterior no la pisa', await texto('.reserva-encontrada-cabecera .codigo-reserva-chico'), 'R000000007')
  sim.buscarLento = null

  titulo('Ventanilla: estados vacío y de error')
  sim.pendientes = []
  await irA('/administrador')
  comprobar('sin reservas pendientes', await texto('.estado-vacio'), 'No hay reservas pendientes para esta agencia.')
  sim.fallaPendientes = true
  await irA('/administrador')
  comprobar('si falla la lista: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudieron cargar las reservas. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallaPendientes = false; sim.pendientes = ['R000000006', 'R000000007']
  await pulsar('.resultado.error button'); await esperar(600)
  comprobar('«Reintentar» recarga la lista', (await filas('.tabla-reservas tbody tr')).startsWith('R000000006'), true)

  titulo('Cliente sin reserva')
  await irA('/administrador/sin-reserva')
  comprobar('título, origen fijo y canal VENTANILLA', [await texto('h1'), await valor('#sucursalOrigen'), await valor('#canal')], ['Cliente sin reserva', 'Agencia Moquegua (MOQ001)', 'VENTANILLA'])
  comprobar('el destino no ofrece la propia agencia', await evaluar(`[...document.querySelectorAll('#sucursalDestino option')].map(o => o.value).join(',')`), ',AQP001,LIM001,ILO001')
  await pulsar('.formulario-reserva button[type=submit]'); await esperar(300)
  comprobar('vacío: errores de documento, destino y contenido; sin llamar a la API', [await errores('.formulario-reserva'), llamadasA('POST', '/api/ventanilla/reservas').length],
    ['El DNI tiene 8 dígitos. | El DNI tiene 8 dígitos. | Elige la sucursal de destino. | Declara el contenido del paquete.', 0])
  comprobar('el foco va al primer error', await enfocado(), 'remitenteNumeroDocumento')
  await escribir('#remitenteNumeroDocumento', '71889345'); await escribir('#destinatarioNumeroDocumento', '70234561'); await esperar(900)
  comprobar('se autocompleta el nombre de ambas personas', await evaluar(`[...document.querySelectorAll('.estado-destinatario')].map(e => e.innerText.trim()).join(' | ')`), '✓ María Elena Rojas Ccori | ✓ Luis Alberto Flores Condori')
  await escribir('#destinatarioNumeroDocumento', '48484848'); await esperar(900)
  comprobar('persona nueva: pide nombre y teléfono', [await existe('#destinatarioNombre'), await existe('#remitenteNombre')], [true, false])
  await escribir('#destinatarioNumeroDocumento', '71889345'); await esperar(900)
  await escribir('#sucursalDestino', 'AQP001'); await escribir('#contenido', 'Cajas'); await esperar(500)
  await pulsar('.formulario-reserva button[type=submit]'); await esperar(300)
  comprobar('remitente = destinatario', await errores('.formulario-reserva'), 'El destinatario no puede ser el mismo remitente.')
  await escribir('#destinatarioNumeroDocumento', '70234561'); await esperar(900)
  await escribir('#sucursalDestino', 'LIM001'); await esperar(600)
  comprobar('destino sin tarifas: aviso y botón deshabilitado', [await texto('.mensaje-aviso'), await deshabilitado('.formulario-reserva button[type=submit]')],
    ['No hay tarifas vigentes entre estas sucursales, así que no se puede reservar esta ruta. Elige otro destino.', true])
  await escribir('#sucursalDestino', 'AQP001'); await esperar(600)
  comprobar('con tarifa vuelve a habilitarse', await deshabilitado('.formulario-reserva button[type=submit]'), false)
  await pulsar('.formulario-reserva button[type=submit]'); await esperar(1200)
  comprobar('se envían los datos del formulario (sin origen: lo pone el servidor)', llamadasA('POST', '/api/ventanilla/reservas').at(-1)?.cuerpo,
    { remitenteTipoDocumento: 'DNI', remitenteNumeroDocumento: '71889345', remitenteNombre: '', remitenteTelefono: '', destinatarioTipoDocumento: 'DNI', destinatarioNumeroDocumento: '70234561', destinatarioNombre: '', destinatarioTelefono: '', sucursalDestino: 'AQP001', contenido: 'Cajas' })
  comprobar('lleva a formalizar la reserva recién creada', await ruta(), '/administrador/formalizar-envio/R000000030')

  sim.crearReserva = () => error(422, 'datos_invalidos', 'Revisa los datos de la reserva.', { remitenteNombre: 'Ingresa los nombres y apellidos.', remitenteTelefono: 'El teléfono tiene 9 dígitos.', contenido: 'Declara el contenido del paquete.' })
  await irA('/administrador/sin-reserva')
  await escribir('#remitenteNumeroDocumento', '71889345'); await escribir('#destinatarioNumeroDocumento', '70234561'); await escribir('#sucursalDestino', 'AQP001'); await escribir('#contenido', 'Cajas'); await esperar(900)
  await pulsar('.formulario-reserva button[type=submit]'); await esperar(700)
  comprobar('errores del servidor por campo (aparecen los datos de la persona nueva)', [await existe('#remitenteNombre'), await errores('.formulario-reserva')],
    [true, 'Ingresa los nombres y apellidos. | El teléfono tiene 9 dígitos. | Declara el contenido del paquete.'])
  sim.crearReserva = null

  titulo('Formalizar envío: pantalla de la diapositiva 19')
  reiniciar()
  await irA('/administrador/formalizar-envio/R000000006')
  comprobar('título y ruta con distancia', [await texto('h1'), await texto('.subtitulo')], ['Formalizar R000000006', 'Agencia Moquegua (MOQ001) → Agencia Arequipa Centro (AQP001)'])
  comprobar('sin bultos: aviso y botones de guardar deshabilitados', [await filas('.tabla-bultos tbody tr'), await deshabilitado(BTN_COBRAR), await deshabilitado(BTN_DESTINO)], ['Aún no agregaste bultos.', true, true])
  comprobar('la dirección solo se habilita para DOMICILIO', [await deshabilitado('#direccionEntrega'), await valor('#tipoEntrega')], [true, 'AGENCIA'])
  await escribir('#tipoEntrega', 'DOMICILIO')
  comprobar('DOMICILIO habilita la dirección', await deshabilitado('#direccionEntrega'), false)
  await escribir('#tipoEntrega', 'AGENCIA')

  titulo('Formalizar envío: bultos, tipo por peso y costo por ruta')
  await escribir('#descripcionBulto', 'Sobre con documentos'); await escribir('#pesoBulto', '0.40'); await esperar(700)
  comprobar('vista previa del bulto: SOBRE por 0.40 kg', await texto('.previa-bulto'), 'SOBRE · S/ 9.15')
  await pulsar('.nuevo-bulto .boton-marino'); await esperar(600)
  await agregarBulto('Caja con ropa', '8.80')
  comprobar('tabla con tipo (por peso) y costo (por ruta)', await filas('.tabla-bultos tbody tr'), '1 Sobre con documentos 0.40 kg SOBRE S/ 9.15 Quitar || 2 Caja con ropa 8.80 kg PAQUETE S/ 14.60 Quitar')
  comprobar('importe y resumen (S/ 23.75 · 2 bultos · 9.20 kg)', [await texto('.importe'), await texto('.resumen-envio .ayuda')], ['Importe S/ 23.75', '2 bultos · 9.20 kg · el código de seguimiento se genera al guardar y se imprime en la etiqueta'])
  comprobar('los campos de captura se limpian', [await valor('#descripcionBulto'), await valor('#pesoBulto')], ['', ''])
  comprobar('ya se puede guardar', [await deshabilitado(BTN_COBRAR), await deshabilitado(BTN_DESTINO)], [false, false])

  await escribir('#descripcionBulto', 'Caja con herramientas'); await escribir('#pesoBulto', '31.00'); await esperar(700)
  comprobar('31 kg es CARGA y no hay ruta vigente: motivo del prototipo', await texto('.previa-bulto'), 'CARGA: sin ruta vigente MOQ→AQP')
  await captura('19_formalizar')
  await pulsar('.nuevo-bulto .boton-marino'); await esperar(400)
  comprobar('no se puede agregar un bulto sin ruta', [(await filas('.tabla-bultos tbody tr')).split(' || ').length, await texto('.nuevo-bulto + .campo-error')], [2, 'CARGA: sin ruta vigente MOQ→AQP'])
  await escribir('#pesoBulto', '301'); await esperar(700)
  comprobar('más de 300 kg: máximo por bulto', await texto('.previa-bulto'), 'El peso máximo por bulto es 300 kg.')
  await escribir('#pesoBulto', 'abc'); await esperar(500)
  comprobar('peso no numérico: se explica sin llamar al servidor', await texto('.previa-bulto'), 'Ingresa el peso en kilogramos, con hasta 2 decimales.')
  await escribir('#pesoBulto', ''); await escribir('#descripcionBulto', '')
  await pulsar('.nuevo-bulto .boton-marino'); await esperar(300)
  comprobar('sin descripción ni peso: avisa', await texto('.nuevo-bulto + .campo-error'), 'Describe el bulto.')
  await pulsar('.tabla-bultos tbody tr:first-child .celda-accion button'); await esperar(600)
  comprobar('quitar un bulto recalcula numeración, importe y resumen', [await filas('.tabla-bultos tbody tr'), await texto('.importe'), await texto('.resumen-envio .ayuda')],
    ['1 Caja con ropa 8.80 kg PAQUETE S/ 14.60 Quitar', 'Importe S/ 14.60', '1 bulto · 8.80 kg · el código de seguimiento se genera al guardar y se imprime en la etiqueta'])
  await agregarBulto('Sobre con documentos', '0.40')

  titulo('Formalizar envío: respuestas lentas (carrera)')
  sim.cotizarLento = true
  await escribir('#descripcionBulto', 'x'); await escribir('#pesoBulto', '5'); await esperar(500) // la vista previa de 5 kg responde tarde
  await escribir('#pesoBulto', '0.40'); await esperar(700) // la actual responde enseguida
  comprobar('se muestra la vista previa del peso actual', await texto('.previa-bulto'), 'SOBRE · S/ 9.15')
  await esperar(RETRASO_MS + 400)
  comprobar('la respuesta tardía del peso anterior no la pisa', await texto('.previa-bulto'), 'SOBRE · S/ 9.15')
  sim.cotizarLento = false
  await escribir('#pesoBulto', ''); await escribir('#descripcionBulto', '')

  titulo('Formalizar envío: guardar')
  await escribir('#tipoEntrega', 'DOMICILIO')
  await pulsar(BTN_COBRAR); await esperar(500)
  comprobar('DOMICILIO sin dirección: se pide antes de enviar', [await errores('.formulario-formalizar'), llamadasA('POST', '/api/envios').length], ['Ingresa la dirección de entrega.', 0])
  await escribir('#direccionEntrega', 'Av. Siempre Viva 742')
  await pulsar(BTN_COBRAR); await esperar(1200)
  comprobar('se envía solo lo que el servidor necesita (sin costos ni tipos)', llamadasA('POST', '/api/envios').at(-1)?.cuerpo,
    { codigoReserva: 'R000000006', tipoEntrega: 'DOMICILIO', direccionEntrega: 'Av. Siempre Viva 742', bultos: [{ descripcion: 'Caja con ropa', pesoKg: '8.80' }, { descripcion: 'Sobre con documentos', pesoKg: '0.40' }] })
  comprobar('lleva al envío registrado', await ruta(), '/administrador/envio/ENK7Q2M9X1BT')

  titulo('Envío registrado y etiqueta')
  await captura('19b_envio_registrado')
  comprobar('título y código de seguimiento', [await texto('h1'), await texto('.codigo-reserva')], ['Envío registrado', 'ENK7Q2M9X1BT'])
  comprobar('resumen del envío', await texto('.mensaje-confirmacion'), '2 bultos · 9.20 kg · S/ 23.75 · entrega a domicilio (Av. Siempre Viva 742)')
  comprobar('tras «Guardar y cobrar ahora», Caja se destaca', await enlaces('.acciones-confirmacion a'), 'Cobrar ahora (Caja)→/administrador/caja?envio=ENK7Q2M9X1BT | Volver a Ventanilla→/administrador')
  comprobar('etiqueta: código, ruta, destinatario, entrega, bultos y reserva', await filas('.etiqueta p'),
    'JATSA Cargo || ENK7Q2M9X1BT || Agencia Moquegua → Agencia Arequipa Centro || Destinatario: Luis Alberto Flores Condori || Entrega: A domicilio · Av. Siempre Viva 742 || 2 bultos · 9.20 kg || Reserva R000000006 · 31/10/2026 09:20')
  await evaluar(`window.__impresiones = 0; window.print = () => { window.__impresiones++ }`)
  await pulsar('.acciones-confirmacion button'); await esperar(200)
  comprobar('«Imprimir etiqueta» abre la impresión', await evaluar('window.__impresiones'), 1)
  await irA('/administrador') // otra entrada del historial: sin el estado «cobrar ahora» de la anterior
  await irA('/administrador/envio/ENK7Q2M9X1BT')
  comprobar('el envío se puede reabrir (reimprimir): «Ir a Caja» sin destacar', await enlaces('.acciones-confirmacion a'), 'Ir a Caja→/administrador/caja | Volver a Ventanilla→/administrador')
  await irA('/administrador/envio/ENZZZZZZZZZZ')
  comprobar('un envío inexistente o de otra agencia', await texto('h1'), 'No encontramos ese envío')

  titulo('Formalizar envío: «Guardar · paga en destino» y errores del servidor')
  reiniciar()
  await irA('/administrador/formalizar-envio/R000000006')
  await agregarBulto('Sobre con documentos', '0.40')
  await pulsar(BTN_DESTINO); await esperar(1200)
  comprobar('guardar para pagar en destino también registra el envío', [await ruta(), await enlaces('.acciones-confirmacion a')], ['/administrador/envio/ENK7Q2M9X1BT', 'Ir a Caja→/administrador/caja | Volver a Ventanilla→/administrador'])

  reiniciar()
  sim.formalizar = () => error(422, 'datos_invalidos', 'Revisa los datos del envío.', { 'bultos.1.pesoKg': 'CARGA: sin ruta vigente MOQ→AQP' })
  await irA('/administrador/formalizar-envio/R000000006')
  await agregarBulto('Sobre con documentos', '0.40')
  await pulsar(BTN_COBRAR); await esperar(800)
  comprobar('error por bulto del servidor (la tarifa cambió): se muestra en la fila', [await ruta(), await texto('.tabla-bultos tbody tr .campo-error')], ['/administrador/formalizar-envio/R000000006', 'CARGA: sin ruta vigente MOQ→AQP'])
  sim.formalizar = () => {
    sim.reservas.R000000006 = reserva('R000000006', 'CANCELADA')
    return error(409, 'reserva_no_formalizable', 'La reserva fue cancelada: no se puede formalizar.')
  }
  await pulsar(BTN_COBRAR); await esperar(1000)
  comprobar('si la reserva cambió (409): aviso y se vuelve a leer su estado', [await texto('h1'), await texto('.confirmacion p')], ['No se puede formalizar R000000006', 'Esta reserva fue cancelada.'])
  sim.formalizar = null
  sim.formalizar = () => error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
  reiniciar()
  await irA('/administrador/formalizar-envio/R000000006')
  await agregarBulto('Sobre con documentos', '0.40')
  await pulsar(BTN_COBRAR); await esperar(800)
  comprobar('error interno: aviso arriba y el botón se reactiva', [await texto('.resultado.error'), await deshabilitado(BTN_COBRAR)], ['Ocurrió un error interno. Inténtalo de nuevo más tarde. Volver a Ventanilla', false])
  sim.formalizar = null

  titulo('Formalizar envío: reservas que no se pueden formalizar')
  for (const [codigoReserva, motivo] of [['R000000001', 'Esta reserva ya fue formalizada.'], ['R000000003', 'Esta reserva fue cancelada.'], ['R000000009', 'Esta reserva venció: pasaron las 72 horas de vigencia.']]) {
    await irA(`/administrador/formalizar-envio/${codigoReserva}`)
    comprobar(`${codigoReserva}: ${motivo}`, [await texto('h1'), await texto('.confirmacion p')], [`No se puede formalizar ${codigoReserva}`, motivo])
  }
  await irA('/administrador/formalizar-envio/R000000999')
  comprobar('reserva de otra agencia o inexistente', await texto('h1'), 'No encontramos esa reserva')

  if (process.env.CAPTURAS) {
    // Revisión en móvil: nada debe obligar a desplazarse de lado.
    titulo('Móvil (solo con CAPTURAS)')
    sim.sesion = ADMIN
    reiniciar()
    for (const [pantalla, nombre] of [['/administrador', 'm_ventanilla'], ['/administrador/formalizar-envio/R000000006', 'm_formalizar'], ['/administrador/sin-reserva', 'm_sin_reserva']]) {
      await irA(pantalla)
      if (nombre === 'm_formalizar') await agregarBulto('Caja con ropa', '8.80')
      await captura(nombre, 390, 844)
      comprobar(`${nombre}: sin desplazamiento horizontal`, await evaluar('document.documentElement.scrollWidth <= window.innerWidth'), true)
    }
  }

  titulo('Accesos por rol')
  sim.sesion = { ...ADMIN, rol: 'CLIENTE', sucursal: null }
  await irA('/administrador')
  comprobar('un cliente no entra a Ventanilla (lo manda a su panel)', await ruta(), '/cliente')
  sim.sesion = null
  await irA('/administrador/formalizar-envio/R000000006')
  comprobar('sin sesión: a Ingresar', (await ruta()).startsWith('/ingresar'), true)
} finally {
  await navegador.cerrar()
}
terminar()
