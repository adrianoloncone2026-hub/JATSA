// Pruebas de las pantallas del módulo 9 en un navegador real: Rastreo (diapositiva 3), Salidas (4), Detalle de salida (5) e
// Historial de envíos (14). La API está interceptada (sin PHP ni base de datos) y algunas respuestas se retrasan a propósito
// para reproducir carreras asíncronas. «Mi panel», la ficha de agencia y «Nueva reserva» se comprueban en publico.mjs y reservas.mjs.
//
// Uso:  1) en frontend/:  npm run dev      2) desde C:\Jatsa:  node frontend/tests/e2e/rastreo.mjs
import { crearInforme, esperar, iniciarNavegador } from './arnes.mjs'

const RETRASO_MS = 1500
const exito = (datos, estado = 200, demoraMs = 0) => ({ estado, cuerpo: { data: datos }, demoraMs })
const error = (estado, codigo, mensaje, campos) => ({ estado, cuerpo: { error: { codigo, mensaje, ...(campos ? { campos } : {}) } } })

const MOQ = { codigo: 'MOQ001', nombre: 'Agencia Moquegua' }
const AQP = { codigo: 'AQP001', nombre: 'Agencia Arequipa Centro' }
const ILO = { codigo: 'ILO001', nombre: 'Agencia Ilo' }
const LIM = { codigo: 'LIM001', nombre: 'Agencia Lima Centro' }
const AGENCIAS = [AQP, ILO, LIM, MOQ].map((a) => ({ ...a, departamento: 'X', provincia: 'X', distrito: 'X', direccion: 'X', telefonos: [] }))
const CLIENTE = { id: 4, nombreUsuario: 'mrojas', nombreCompleto: 'María Elena Rojas Ccori', rol: 'CLIENTE', sucursal: null }
const ADMIN = { id: 2, nombreUsuario: 'lflores', nombreCompleto: 'Luis Flores', rol: 'ADMINISTRADOR', sucursal: { id: 3, ...MOQ } }

// ── Datos de ejemplo (diapositivas 3, 4, 5 y 14) ────────────────────────────
const hito = (fechaHora, tipo, texto) => ({ fechaHora, tipo, texto })
const ETAPAS = (n) => ['REGISTRADO', 'EN_TRANSITO', 'EN_DESTINO', 'ENTREGADO'].map((estado, i) => ({ estado, cumplida: i <= n, actual: i === n }))
const rastreoBase = {
  codigoSeguimiento: 'ENZ5H0D3V7NJ', estado: 'EN_TRANSITO', etapas: ETAPAS(1), origen: MOQ, destino: AQP, cantidadBultos: 1, pesoTotalKg: '18.50', saldo: '0.00',
  recorrido: [hito('2026-10-14 14:00:00', 'registro', 'Recibido en Agencia Moquegua (MOQ001)'), hito('2026-10-14 14:20:00', 'carga', 'Cargado en el viaje VJ2026101402'),
    hito('2026-10-14 15:00:00', 'salida', 'Salió hacia Agencia Arequipa Centro'), hito('2026-10-14 19:00:00', 'estimada', 'Llegada estimada (ruta de 4 h)')],
  viajeActual: { codigo: 'VJ2026101402', vehiculo: { tipo: 'FURGON', placa: 'B7K-210' }, fechaHoraSalida: '2026-10-14 15:00:00', estado: 'EN_RUTA', origen: MOQ, destino: AQP },
  rutaId: 'MOQ001-AQP001',
}
const RASTREOS = {
  ENZ5H0D3V7NJ: rastreoBase,
  EN7K4Q2M9X1B: { ...rastreoBase, codigoSeguimiento: 'EN7K4Q2M9X1B', estado: 'ENTREGADO', etapas: ETAPAS(3), pesoTotalKg: '9.20', viajeActual: null,
    recorrido: [hito('2026-10-10 10:02:00', 'registro', 'Recibido en Agencia Moquegua (MOQ001)'), hito('2026-10-10 14:00:00', 'salida', 'Salió hacia Agencia Arequipa Centro'), hito('2026-10-10 18:30:00', 'llegada', 'Llegó a Agencia Arequipa Centro'), hito('2026-10-12 11:30:00', 'entrega', 'Entregado en Agencia Arequipa Centro')] },
  ENDEVUELTO01: { ...rastreoBase, codigoSeguimiento: 'ENDEVUELTO01', estado: 'DEVUELTO', etapas: ETAPAS(-1).map((e) => ({ ...e, cumplida: false, actual: false })), viajeActual: null, llegadaEstimada: null },
  ENSALDO00001: { ...rastreoBase, codigoSeguimiento: 'ENSALDO00001', estado: 'REGISTRADO', etapas: ETAPAS(0), saldo: '14.60', viajeActual: null, recorrido: [rastreoBase.recorrido[0]] },
}

const rutaDe = (id, origen, destino, horarios, horas, desde, extra = {}) => ({ id, origen, destino, horarios, programado: false, horas, desde, ...extra })
const SALIDAS = [
  rutaDe('MOQ001-AQP001', MOQ, AQP, ['14:00', '15:00'], 4, '9.15', { tipos: { SOBRE: '9.15', PAQUETE: '14.60' } }),
  rutaDe('AQP001-LIM001', AQP, LIM, [], 18, '30.20', { tipos: { PAQUETE: '30.20' } }),
  rutaDe('ILO001-MOQ001', ILO, MOQ, ['07:00'], 2, '8.50', { tipos: { SOBRE: '8.50' } }),
  rutaDe('LIM001-ILO001', LIM, ILO, [], 24, '136.00', { programado: true, tipos: { CARGA: '136.00' } }),
]
const DETALLE_RUTA = {
  id: 'MOQ001-AQP001', origen: MOQ, destino: AQP, distanciaKm: '230', horas: 4, horarios: ['14:00', '15:00'], programado: false, llegadaAproximada: { desde: '18:00', hasta: '19:00' },
  tarifas: [{ tipo: 'SOBRE', precioPorBulto: '9.15' }, { tipo: 'PAQUETE', precioPorBulto: '14.60' }],
  recientes: [{ codigo: 'VJ2026101402', fechaHoraSalida: '2026-10-14 15:00:00', fechaHoraLlegada: null, estado: 'EN_RUTA' }, { codigo: 'VJ2026101001', fechaHoraSalida: '2026-10-10 14:00:00', fechaHoraLlegada: '2026-10-10 18:30:00', estado: 'TERMINADO' }],
}

const bulto = (numero, descripcion, pesoKg, tipo, costo) => ({ numero, descripcion, pesoKg, tipo, costo })
const MIS_ENVIOS = [
  { codigoSeguimiento: 'ENM2P6T8R1WQ', estado: 'EN_DESTINO', rol: 'RECIBI', fecha: '2026-10-13', otraParte: 'Pedro Mamani Huanca', importe: '8.50' },
  { codigoSeguimiento: 'EN7K4Q2M9X1B', estado: 'ENTREGADO', rol: 'ENVIE', fecha: '2026-10-10', otraParte: 'Luis Alberto Flores Condori', importe: '23.75' },
]
const DETALLES = {
  EN7K4Q2M9X1B: {
    codigoSeguimiento: 'EN7K4Q2M9X1B', estado: 'ENTREGADO', rol: 'ENVIE', otraParte: 'Luis Alberto Flores Condori', fechaHoraRegistro: '2026-10-10 10:02:00', fechaHoraEntrega: '2026-10-12 11:30:00',
    origen: MOQ, destino: AQP, viaje: { codigo: 'VJ2026101001', fechaHoraSalida: '2026-10-10 14:00:00', fechaHoraLlegada: '2026-10-10 18:30:00' },
    bultos: [bulto(1, 'Sobre con documentos', '0.40', 'SOBRE', '9.15'), bulto(2, 'Caja con ropa', '8.80', 'PAQUETE', '14.60')], cantidadBultos: 2, pesoTotalKg: '9.20',
    importe: '23.75', pagado: '23.75', saldo: '0.00', pagos: [{ monto: '23.75', metodoPago: 'EFECTIVO', estado: 'REGISTRADO', sucursal: 'MOQ001', fechaHora: '2026-10-10 10:04:00' }],
  },
  ENM2P6T8R1WQ: {
    codigoSeguimiento: 'ENM2P6T8R1WQ', estado: 'EN_DESTINO', rol: 'RECIBI', otraParte: 'Pedro Mamani Huanca', fechaHoraRegistro: '2026-10-13 07:20:00', fechaHoraEntrega: null,
    origen: ILO, destino: MOQ, viaje: { codigo: 'VJ2026101301', fechaHoraSalida: '2026-10-13 07:00:00', fechaHoraLlegada: '2026-10-13 09:15:00' },
    bultos: [bulto(1, 'Sobre con documentos', '0.60', 'SOBRE', '8.50')], cantidadBultos: 1, pesoTotalKg: '0.60', importe: '8.50', pagado: '0.00', saldo: '8.50', pagos: [],
  },
}

const sim = { sesion: null, lentoRastreo: null, fallaRastreo: false, fallaSalidas: false, lentoSalidas: null, fallaHistorial: false, vacio: false }

const { comprobar, titulo, terminar } = crearInforme()
const navegador = await iniciarNavegador(9348, ({ metodo, ruta: rutaApi, url }) => {
  const q = (clave) => url.searchParams.get(clave) ?? ''
  if (rutaApi === '/api/auth/yo') return sim.sesion ? exito({ usuario: sim.sesion }) : error(401, 'no_autenticado', 'Debes iniciar sesión.')
  if (rutaApi === '/api/agencias') return exito({ agencias: AGENCIAS })

  const rastreo = rutaApi.match(/^\/api\/rastreo\/(EN[A-Z0-9]{10})$/)
  if (rastreo) {
    if (sim.fallaRastreo) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    return RASTREOS[rastreo[1]] ? exito({ envio: RASTREOS[rastreo[1]] }, 200, sim.lentoRastreo === rastreo[1] ? RETRASO_MS : 0) : error(404, 'envio_no_encontrado', 'No encontramos un envío con ese código. Revísalo e inténtalo de nuevo.')
  }

  if (rutaApi === '/api/salidas') {
    if (sim.fallaSalidas) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    let lista = SALIDAS.filter((s) => (!q('origen') || s.origen.codigo === q('origen')) && (!q('destino') || s.destino.codigo === q('destino')) && (!q('tipo') || s.tipos[q('tipo')]))
    if (q('tipo')) lista = lista.map((s) => ({ ...s, desde: s.tipos[q('tipo')] }))
    return exito({ salidas: lista.map(({ tipos, ...s }) => s) }, 200, sim.lentoSalidas !== null && q('origen') === sim.lentoSalidas ? RETRASO_MS : 0)
  }
  const detalleRuta = rutaApi.match(/^\/api\/salidas\/([A-Z0-9-]+)$/)
  if (detalleRuta) return detalleRuta[1] === 'MOQ001-AQP001' ? exito({ ruta: DETALLE_RUTA }) : error(404, 'ruta_no_encontrada', 'No encontramos esa ruta.')

  if (rutaApi === '/api/mis-envios/resumen') return exito({ enCurso: 1, saldoPendiente: '0.00', porRecoger: [], hayMasPorRecoger: false })
  if (rutaApi === '/api/mis-envios') {
    if (sim.fallaHistorial) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    if (sim.vacio) return exito({ envios: [], hayMas: false })
    const rol = q('rol'); const estado = q('estado'); const texto = q('q').toLowerCase()
    return exito({
      hayMas: false,
      envios: MIS_ENVIOS.filter((e) => (!rol || e.rol === rol) && (!estado || e.estado === estado) && (!q('desde') || e.fecha >= q('desde')) && (!q('hasta') || e.fecha <= q('hasta')) && (!texto || e.codigoSeguimiento.toLowerCase().includes(texto) || e.otraParte.toLowerCase().includes(texto))),
    })
  }
  const uno = rutaApi.match(/^\/api\/mis-envios\/(EN[A-Z0-9]{10})$/)
  if (uno) return DETALLES[uno[1]] ? exito({ envio: DETALLES[uno[1]] }) : error(404, 'envio_no_encontrado', 'No se encontró el envío.')
  return null
})
const { escribir, pulsar, texto, valor, existe, ruta, evaluar, llamadas } = navegador

// Espera CONDICIONES, no un tiempo fijo: pasado un mínimo (que cubre el instante de espera de la búsqueda por texto), la pantalla
// queda «estable» cuando no hay ningún indicador de carga y su texto no cambia entre dos lecturas seguidas. Las pruebas de carreras
// asíncronas (respuestas retrasadas a propósito) conservan sus esperas fijas: ahí el tiempo es parte de lo que se prueba.
async function estable(minimoMs = 250, limiteMs = 10000) {
  await esperar(minimoMs)
  const inicio = Date.now()
  let anterior = null
  while (Date.now() - inicio < limiteMs) {
    const estado = await evaluar(`JSON.stringify({ cargando: !!document.querySelector('[role=status], .cargando'), texto: document.body.innerText })`)
    const lectura = estado ? JSON.parse(estado) : null
    // «Estable» exige que la página ya haya pintado algo (una página en blanco sin indicador de carga no es una pantalla lista).
    if (lectura && !lectura.cargando && lectura.texto.trim().length > 0 && estado === anterior) return
    anterior = estado
    await esperar(150)
  }
  throw new Error('La pantalla no se estabilizó a tiempo')
}
const irA = async (rutaNueva) => { await navegador.irA(rutaNueva, 100); await estable(300) }
const captura = async (nombre, ancho = 1280, alto = 800) => { if (process.env.CAPTURAS) await navegador.captura(`${process.env.CAPTURAS}/${nombre}.png`, ancho, alto) }
const llamadasA = (rutaApi) => llamadas.filter((l) => l.metodo === 'GET' && l.ruta === rutaApi)
const enlaces = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(a => a.innerText.trim().replace(/\\s+/g,' ') + '→' + a.getAttribute('href')).join(' | ')`)
const filas = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(r => r.innerText.replace(/\\s+/g,' ').trim()).join(' || ')`)
const consultas = (rutaApi) => evaluar('0').then(() => llamadasA(rutaApi).length)

try {
  titulo('Rastreo: pantalla de la diapositiva 3')
  await irA('/rastreo')
  comprobar('sin código: el formulario y ningún resultado', [await texto('h1'), await existe('#codigoSeguimiento'), await existe('.rastreo-resultado')], ['Rastrea tu envío', true, false])
  await escribir('#codigoSeguimiento', 'EN12'); await pulsar('.buscar-reserva button'); await estable()
  comprobar('un código mal escrito se explica y no llama a la API', [await texto('.campo-error'), (await evaluar('performance.getEntriesByType("resource").filter(r => r.name.includes("/api/rastreo/")).length'))], ['El código de seguimiento empieza con EN y tiene 12 caracteres. Por ejemplo: ENZ5H0D3V7NJ.', 0])
  await escribir('#codigoSeguimiento', ' enz5h0d3v7nj '); await pulsar('.buscar-reserva button'); await estable()
  comprobar('acepta minúsculas y espacios y deja el código en la dirección', await ruta(), '/rastreo?codigo=ENZ5H0D3V7NJ')
  comprobar('código, estado y resumen de la ruta (con el saldo)', [await texto('.rastreo-cabecera h2'), await texto('.rastreo-cabecera .insignia'), await texto('.rastreo-estado .ruta-texto')],
    ['ENZ5H0D3V7NJ', 'EN TRÁNSITO', 'Agencia Moquegua → Agencia Arequipa Centro · 1 bulto · 18.50 kg · Saldo S/ 0.00'])
  comprobar('las cuatro etapas: ✓ cumplida, la actual marcada y las pendientes', [await filas('.etapas li'), await evaluar(`document.querySelector('.etapa.actual').innerText.trim().replace(/\\s+/g,' ')`)], ['✓ REGISTRADO || 2 EN TRÁNSITO || 3 EN DESTINO || 4 ENTREGADO', '2 EN TRÁNSITO'])
  comprobar('el recorrido (con la llegada estimada)', await filas('.recorrido li'), '14/10 14:00 Recibido en Agencia Moquegua (MOQ001) || 14/10 14:20 Cargado en el viaje VJ2026101402 || 14/10 15:00 Salió hacia Agencia Arequipa Centro || 14/10 ≈19:00 Llegada estimada (ruta de 4 h)')
  comprobar('el viaje en curso y el acceso a las próximas salidas', [await texto('.rastreo-viaje h2'), await texto('.rastreo-viaje p'), await texto('.rastreo-viaje a')], ['Moquegua → Arequipa', 'Viaje VJ2026101402 · FURGON B7K-210 · salió 15:00 · en ruta', 'Salidas frecuentes Moquegua → Arequipa'])
  comprobar('«Ver horarios de esta ruta» y «Ver agencia de destino» enlazan con su ruta y su agencia', await enlaces('.rastreo-estado .acciones-formulario a'), 'Ver horarios de esta ruta→/salidas/MOQ001-AQP001 | Ver agencia de destino→/agencias?agencia=AQP001')
  comprobar('no muestra documentos, teléfonos ni nombres de personas', /DNI|RUC|teléfono|María|Luis/.test(await texto('.rastreo')), false)
  await captura('03_rastreo')

  titulo('Rastreo: otros estados, errores y carrera')
  await irA('/rastreo?codigo=EN7K4Q2M9X1B')
  comprobar('entregado: las cuatro etapas cumplidas, entrega al final del recorrido y sin viaje en curso', [await texto('.rastreo-cabecera .insignia'), (await filas('.etapas li')), (await filas('.recorrido li')).split(' || ').at(-1), await texto('.rastreo-viaje p')],
    ['ENTREGADO', '✓ REGISTRADO || ✓ EN TRÁNSITO || ✓ EN DESTINO || 4 ENTREGADO', '12/10 11:30 Entregado en Agencia Arequipa Centro', 'El envío ya fue entregado.'])
  await irA('/rastreo?codigo=ENDEVUELTO01')
  comprobar('devuelto: aviso en lugar de las etapas', [await texto('.rastreo-estado .mensaje-aviso'), await existe('.etapas')], ['Este envío fue devuelto.', false])
  await irA('/rastreo?codigo=ENSALDO00001')
  comprobar('registrado con saldo pendiente: solo «Recibido…» y el saldo', [await filas('.recorrido li'), (await texto('.rastreo-estado .ruta-texto')).endsWith('Saldo S/ 14.60')], ['14/10 14:00 Recibido en Agencia Moquegua (MOQ001)', true])
  await irA('/rastreo?codigo=ENZZZZZZZZZZ')
  comprobar('código inexistente: el mensaje del servidor y sin resultado', [await texto('.resultado.error'), await existe('.rastreo-resultado')], ['No encontramos un envío con ese código. Revísalo e inténtalo de nuevo.', false])
  sim.fallaRastreo = true
  await irA('/rastreo?codigo=ENZ5H0D3V7NJ')
  comprobar('si falla: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudo consultar el envío. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallaRastreo = false
  await pulsar('.resultado.error button'); await estable()
  comprobar('«Reintentar» consulta otra vez', await texto('.rastreo-cabecera h2'), 'ENZ5H0D3V7NJ')
  sim.lentoRastreo = 'ENZ5H0D3V7NJ'
  await irA('/rastreo')
  await escribir('#codigoSeguimiento', 'ENZ5H0D3V7NJ'); await pulsar('.buscar-reserva button'); await esperar(300)
  await escribir('#codigoSeguimiento', 'EN7K4Q2M9X1B'); await pulsar('.buscar-reserva button'); await esperar(500)
  comprobar('dos búsquedas seguidas: se muestra la última', await texto('.rastreo-cabecera h2'), 'EN7K4Q2M9X1B')
  await esperar(RETRASO_MS + 300)
  comprobar('la respuesta tardía de la anterior no la pisa', await texto('.rastreo-cabecera h2'), 'EN7K4Q2M9X1B')
  sim.lentoRastreo = null

  titulo('Salidas entre sucursales: pantalla de la diapositiva 4')
  await irA('/salidas')
  comprobar('título y aclaración', [await texto('h1'), await texto('.salidas .ayuda')], ['Salidas entre sucursales', 'Referencia histórica: las horas en que más salieron los últimos viajes de cada ruta. No es un horario garantizado; confirma la salida en la agencia.'])
  comprobar('las rutas con horario, tiempo y precio «desde» (o «viaje programado, sin historial» / «sin historial de salidas»)', await filas('.salida-fila'),
    '→ Agencia Moquegua → Agencia Arequipa Centro Salidas frecuentes: 14:00 · 15:00 · 4 h · desde S/ 9.15 Ver || → Agencia Arequipa Centro → Agencia Lima Centro Salidas frecuentes: sin historial de salidas · 18 h · desde S/ 30.20 Ver || → Agencia Ilo → Agencia Moquegua Salidas frecuentes: 07:00 · 2 h · desde S/ 8.50 Ver || → Agencia Lima Centro → Agencia Ilo Salidas frecuentes: viaje programado, sin historial · 24 h · desde S/ 136.00 Ver')
  comprobar('«Ver» lleva al detalle de la ruta', (await enlaces('.salida-fila a')).split(' | ')[0], 'Ver→/salidas/MOQ001-AQP001')
  comprobar('los filtros se llenan con las agencias', await evaluar(`[...document.querySelectorAll('#filtroOrigen option')].map(o => o.innerText).join(',')`), 'Todas,Agencia Arequipa Centro,Agencia Ilo,Agencia Lima Centro,Agencia Moquegua')
  await escribir('#filtroOrigen', 'MOQ001'); await estable()
  comprobar('filtrar por origen: consulta con el filtro, lo deja en la dirección y muestra solo esa ruta', [await ruta(), (await filas('.salida-fila')).startsWith('→ Agencia Moquegua → Agencia Arequipa'), await evaluar(`document.querySelectorAll('.salida-fila').length`)], ['/salidas?origen=MOQ001', true, 1])
  await escribir('#filtroOrigen', ''); await escribir('#filtroTipo', 'PAQUETE'); await estable()
  comprobar('filtrar por tipo cambia el precio «desde»', await filas('.salida-fila'), '→ Agencia Moquegua → Agencia Arequipa Centro Salidas frecuentes: 14:00 · 15:00 · 4 h · desde S/ 14.60 Ver || → Agencia Arequipa Centro → Agencia Lima Centro Salidas frecuentes: sin historial de salidas · 18 h · desde S/ 30.20 Ver')
  await escribir('#filtroDestino', 'ILO001'); await estable()
  comprobar('sin coincidencias: mensaje', await texto('.estado-vacio'), 'No hay salidas que coincidan con esos filtros.')
  await irA('/salidas?origen=ILO001')
  comprobar('los filtros de la dirección se aplican al entrar', [await valor('#filtroOrigen'), await filas('.salida-fila')], ['ILO001', '→ Agencia Ilo → Agencia Moquegua Salidas frecuentes: 07:00 · 2 h · desde S/ 8.50 Ver'])
  sim.fallaSalidas = true
  await irA('/salidas')
  comprobar('si falla: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudieron cargar las salidas. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallaSalidas = false
  await pulsar('.resultado.error button'); await estable()
  comprobar('«Reintentar» recarga', await evaluar(`document.querySelectorAll('.salida-fila').length`), 4)
  sim.lentoSalidas = 'LIM001'
  await irA('/salidas')
  await escribir('#filtroOrigen', 'LIM001'); await esperar(300)
  await escribir('#filtroOrigen', 'ILO001'); await esperar(500)
  comprobar('dos filtros seguidos: se muestra el último', await filas('.salida-fila'), '→ Agencia Ilo → Agencia Moquegua Salidas frecuentes: 07:00 · 2 h · desde S/ 8.50 Ver')
  await esperar(RETRASO_MS + 300)
  comprobar('la respuesta tardía del filtro anterior no la pisa', await filas('.salida-fila'), '→ Agencia Ilo → Agencia Moquegua Salidas frecuentes: 07:00 · 2 h · desde S/ 8.50 Ver')
  sim.lentoSalidas = null
  await captura('04_salidas')

  titulo('Detalle de salida: pantalla de la diapositiva 5')
  await irA('/salidas/MOQ001-AQP001')
  comprobar('ruta y título', [await texto('h1'), await texto('.detalle-salida .ruta-texto')], ['Moquegua → Arequipa', 'Agencia Moquegua (MOQ001) → Agencia Arequipa Centro (AQP001)'])
  comprobar('datos de la ruta: distancia, tiempo, salida frecuente y llegada', await filas('.detalle-salida > .datos-iconos .dato-icono'), 'Distancia 230 km || Tiempo estimado 4 h || Salida frecuente 14:00 y 15:00 || Llegada aproximada 18:00 – 19:00')
  comprobar('precios por bulto y aclaración histórica', [await texto('.llega-a-tiempo h2'), await filas('.llega-a-tiempo li'), await texto('.llega-a-tiempo .ayuda')], ['Precio por bulto', 'Sobre desde S/ 9.15 || Paquete desde S/ 14.60', 'Con un viaje de 4 h, una salida a la hora frecuente llegaría aproximadamente a la hora indicada. Es una referencia histórica, no una promesa: no asegura que haya una salida hoy ni que se admita tu paquete. Confirma la salida en la agencia.'])
  comprobar('precios', await filas('.llega-a-tiempo li'), 'Sobre desde S/ 9.15 || Paquete desde S/ 14.60')
  comprobar('salidas recientes (historial) y sin promesa de salida hoy', [await texto('.detalle-salida > h2'), (await texto('.llega-a-tiempo')).includes('Si dejas tu paquete hoy')], ['Salidas recientes (historial)', false]);
  comprobar('salidas recientes: viajes del historial', await filas('.tabla-salidas tbody tr'), 'VJ2026101402 14/10 15:00 — EN RUTA || VJ2026101001 10/10 14:00 10/10 18:30 TERMINADO')
  comprobar('acciones con la ruta ya elegida', await enlaces('.detalle-salida .acciones-formulario a'),
    'Reservar este envío→/cliente/nueva-reserva?origen=MOQ001&destino=AQP001 | Cotizar→/cotizador?origen=MOQ001&destino=AQP001 | Agencia de origen→/agencias?agencia=MOQ001 | Rastrear un envío→/rastreo | Volver a salidas→/salidas')
  await captura('05_detalle_salida')
  await irA('/salidas/ZZZ999-AAA111')
  comprobar('ruta inexistente', [await texto('h1'), await texto('.confirmacion p')], ['No encontramos esa ruta', 'No encontramos esa ruta.'])

  titulo('Historial de envíos: pantalla de la diapositiva 14')
  sim.sesion = CLIENTE
  await irA('/cliente/historial')
  comprobar('título, filtros y el botón «Exportar lista» deshabilitado (módulo 12)', [await texto('h1'), await existe('#buscar'), await evaluar(`document.querySelector('.barra-historial button').disabled`)], ['Historial de envíos', true, true])
  comprobar('mis envíos: «Envié/Recibí · fecha · importe» y estado', await filas('.envio-fila'), 'ENM2P6T8R1WQ Recibí · 13/10 · S/ 8.50 EN DESTINO Ver || EN7K4Q2M9X1B Envié · 10/10 · S/ 23.75 ENTREGADO Ver')
  comprobar('el menú del cliente marca Historial', (await enlaces('nav.menu a')).includes('Historial de envíos→/cliente/historial'), true)
  await pulsar('.envio-fila:nth-child(2) button'); await estable()
  comprobar('detalle: destinatario, entrega, viaje y pago (diapositiva 14)', [await texto('.detalle-envio h2'), await filas('.detalle-envio > p:not(.total-historial)')],
    ['EN7K4Q2M9X1B', 'Para Luis Alberto Flores Condori || Entregado 12/10 11:30 en Agencia Arequipa Centro || Viaje VJ2026101001: salió 10/10 14:00, llegó 18:30 || Pagado S/ 23.75 · EFECTIVO · MOQ001'])
  comprobar('bultos con su tipo y costo, y el total con el saldo', [await filas('.tabla-bultos-historial tbody tr'), await texto('.total-historial')],
    ['1 Sobre con documentos 0.40 kg SOBRE S/ 9.15 || 2 Caja con ropa 8.80 kg PAQUETE S/ 14.60', 'Total S/ 23.75 · Saldo S/ 0.00'])
  comprobar('enlaces: rastreo público y mis pagos; el envío abierto queda en la dirección', [await enlaces('.detalle-envio .acciones-formulario a'), await ruta()], ['Rastreo público→/rastreo?codigo=EN7K4Q2M9X1B | Mis pagos→/cliente/pagos', '/cliente/historial?envio=EN7K4Q2M9X1B'])
  await captura('14_historial')
  await pulsar('.detalle-envio .entrega-cabecera button'); await estable()
  comprobar('«Cerrar» oculta el detalle', [await existe('.detalle-envio'), await ruta()], [false, '/cliente/historial'])
  await pulsar('.envio-fila:nth-child(1) button'); await estable()
  comprobar('recibido sin pagos: «De …», en destino y «Sin pagos registrados»; saldo pendiente', [await filas('.detalle-envio > p:not(.total-historial)'), await texto('.total-historial')],
    ['De Pedro Mamani Huanca || EN DESTINO · Agencia Ilo → Agencia Moquegua || Viaje VJ2026101301: salió 13/10 07:00, llegó 09:15 || Sin pagos registrados', 'Total S/ 8.50 · Saldo S/ 8.50'])
  await irA('/cliente/historial?envio=EN7K4Q2M9X1B')
  comprobar('un envío enlazado desde «Mi panel» se abre directamente', await texto('.detalle-envio h2'), 'EN7K4Q2M9X1B')
  await irA('/cliente/historial?envio=ENB3F8L0Q2ZD')
  comprobar('un envío ajeno: «No se encontró el envío.»', await texto('.detalle-envio .resultado.error'), 'No se encontró el envío.')

  titulo('Historial: filtros, vacío y error')
  await irA('/cliente/historial')
  await escribir('#rol', 'ENVIE'); await estable()
  comprobar('filtro «Envié»: consulta con el rol y muestra solo lo enviado', [llamadasA('/api/mis-envios').at(-1) !== undefined, await filas('.envio-fila')], [true, 'EN7K4Q2M9X1B Envié · 10/10 · S/ 23.75 ENTREGADO Ver'])
  await escribir('#rol', ''); await escribir('#estado', 'EN_DESTINO'); await estable()
  comprobar('filtro por estado', await filas('.envio-fila'), 'ENM2P6T8R1WQ Recibí · 13/10 · S/ 8.50 EN DESTINO Ver')
  await escribir('#estado', ''); await escribir('#desde', '2026-10-12'); await estable()
  comprobar('filtro «Desde»', await filas('.envio-fila'), 'ENM2P6T8R1WQ Recibí · 13/10 · S/ 8.50 EN DESTINO Ver')
  await escribir('#desde', ''); await escribir('#hasta', '2026-10-10'); await estable()
  comprobar('filtro «Hasta» (incluye el día)', await filas('.envio-fila'), 'EN7K4Q2M9X1B Envié · 10/10 · S/ 23.75 ENTREGADO Ver')
  await escribir('#hasta', ''); await escribir('#buscar', 'luis'); await estable(700)
  comprobar('búsqueda por nombre (con un instante de espera tras la última tecla)', await filas('.envio-fila'), 'EN7K4Q2M9X1B Envié · 10/10 · S/ 23.75 ENTREGADO Ver')
  await escribir('#buscar', 'zzz'); await estable(700)
  comprobar('sin coincidencias: mensaje con los filtros', await texto('.estado-vacio p'), 'No hay envíos que coincidan con esos filtros.')
  sim.vacio = true
  await irA('/cliente/historial')
  comprobar('sin envíos: mensaje con acceso a reservar', [await texto('.estado-vacio p'), await texto('.estado-vacio a')], ['Todavía no tienes envíos.', 'Hacer una reserva'])
  sim.vacio = false; sim.fallaHistorial = true
  await irA('/cliente/historial')
  comprobar('si falla: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudieron cargar tus envíos. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallaHistorial = false
  await pulsar('.resultado.error button'); await estable()
  comprobar('«Reintentar» recarga', await evaluar(`document.querySelectorAll('.envio-fila').length`), 2)

  if (process.env.CAPTURAS) {
    // Revisión en móvil: nada debe obligar a desplazarse de lado.
    titulo('Móvil (solo con CAPTURAS)')
    for (const [sesion, pantalla, nombre] of [[null, '/rastreo?codigo=ENZ5H0D3V7NJ', 'm_rastreo'], [null, '/salidas', 'm_salidas'], [null, '/salidas/MOQ001-AQP001', 'm_detalle_salida'], [CLIENTE, '/cliente/historial?envio=EN7K4Q2M9X1B', 'm_historial']]) {
      sim.sesion = sesion
      await irA(pantalla)
      await captura(nombre, 390, 844)
      comprobar(`${nombre}: sin desplazamiento horizontal`, await evaluar('document.documentElement.scrollWidth <= window.innerWidth'), true)
    }
  }

  titulo('Accesos por rol')
  sim.sesion = null
  await irA('/cliente/historial')
  comprobar('sin sesión: el historial pide ingresar', (await ruta()).startsWith('/ingresar'), true)
  sim.sesion = ADMIN
  await irA('/cliente/historial')
  comprobar('un administrador entra al historial desde Mis encomiendas', await ruta(), '/cliente/historial')
  await irA('/rastreo?codigo=ENZ5H0D3V7NJ')
  comprobar('el rastreo y las salidas son públicos: también los ve quien tiene sesión', await texto('.rastreo-cabecera h2'), 'ENZ5H0D3V7NJ')
} finally {
  await navegador.cerrar()
}
terminar()
