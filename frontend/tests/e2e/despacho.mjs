// Pruebas de las pantallas del módulo 7 en un navegador real: Despacho (Administrador, diapositiva 21), Mis viajes y
// Detalle del viaje (Transportista, diapositivas 24 y 25). La API está interceptada (sin PHP ni base de datos) y algunas
// respuestas se retrasan a propósito para reproducir carreras asíncronas.
//
// Uso:  1) en frontend/:  npm run dev      2) desde C:\Jatsa:  node frontend/tests/e2e/despacho.mjs
import { crearInforme, esperar, iniciarNavegador } from './arnes.mjs'

const exito = (datos, estado = 200, demoraMs = 0) => ({ estado, cuerpo: { data: datos }, demoraMs })
const error = (estado, codigo, mensaje, campos) => ({ estado, cuerpo: { error: { codigo, mensaje, ...(campos ? { campos } : {}) } } })

const SUC = {
  MOQ001: { codigo: 'MOQ001', nombre: 'Agencia Moquegua' },
  AQP001: { codigo: 'AQP001', nombre: 'Agencia Arequipa Centro' },
  ILO001: { codigo: 'ILO001', nombre: 'Agencia Ilo' },
  LIM001: { codigo: 'LIM001', nombre: 'Agencia Lima Centro' },
}
const ADMIN = { id: 2, nombreUsuario: 'lflores', nombreCompleto: 'Luis Flores', rol: 'ADMINISTRADOR', sucursal: { id: 3, ...SUC.MOQ001 } }
const TRANSP = { id: 5, nombreUsuario: 'pmamani', nombreCompleto: 'Pedro Mamani', rol: 'TRANSPORTISTA', sucursal: { id: 2, ...SUC.AQP001 } }
const VEHICULOS = {
  'C3M-956': { placa: 'C3M-956', tipo: 'CAMIONETA', capacidadKg: '1500.00' },
  'B7K-210': { placa: 'B7K-210', tipo: 'FURGON', capacidadKg: '6500.00' },
  'V2A-845': { placa: 'V2A-845', tipo: 'CAMION', capacidadKg: '18000.00' },
}
const centimos = (t) => Math.round(Number(t) * 100)
const kg = (c) => `${Math.floor(c / 100)}.${String(c % 100).padStart(2, '0')}`

const sim = { sesion: ADMIN, viajes: [], cargas: {}, espera: [], contador: 4, lentoViajes: false, fallaSalidas: false, fallaMis: false, cargar: null, salida: null, llegada: null }

const carga = (codigo, bultos, pesoKg, destino = SUC.AQP001) => ({ codigoSeguimiento: codigo, bultos, pesoKg, destinoFinal: destino, cargadoEn: '2026-10-14 14:20:00' })
const viaje = (codigo, estado, destino, placa, extra = {}) => ({
  codigo, estado, origen: SUC.MOQ001, destino, vehiculo: VEHICULOS[placa], transportista: 'Pedro Mamani Huanca',
  fechaHoraSalida: null, fechaHoraLlegada: null, llegadaEstimada: null, ...extra,
})

function reiniciar() {
  sim.viajes = [
    viaje('VJ2026101402', 'EN_RUTA', SUC.AQP001, 'B7K-210', { fechaHoraSalida: '2026-10-14 15:00:00', llegadaEstimada: '2026-10-14 19:00:00' }),
    viaje('VJ2026103101', 'PROGRAMADO', SUC.AQP001, 'C3M-956'),
    viaje('VJ2026103102', 'PROGRAMADO', SUC.AQP001, 'B7K-210'),
    viaje('VJ2026103103', 'PROGRAMADO', SUC.ILO001, 'V2A-845'),
    viaje('VJ2026101001', 'TERMINADO', SUC.AQP001, 'C3M-956', { fechaHoraSalida: '2026-10-10 14:00:00', fechaHoraLlegada: '2026-10-10 18:30:00' }),
  ]
  sim.cargas = { VJ2026101402: [carga('ENZ5H0D3V7NJ', 1, '18.50')], VJ2026103101: [], VJ2026103102: [], VJ2026103103: [], VJ2026101001: [carga('EN7K4Q2M9X1B', 2, '9.20')] }
  sim.espera = [
    { codigoSeguimiento: 'EN4T7Q0P2LKX', estado: 'REGISTRADO', bultos: 2, pesoKg: '9.20', destino: SUC.AQP001 },
    { codigoSeguimiento: 'ENQ8W2E4R6TY', estado: 'REGISTRADO', bultos: 1, pesoKg: '3.10', destino: SUC.AQP001 },
    { codigoSeguimiento: 'ENA1S3D5F7GH', estado: 'REGISTRADO', bultos: 1, pesoKg: '25.00', destino: SUC.ILO001 },
  ]
}
reiniciar()

function resumen(v) {
  const c = sim.cargas[v.codigo] ?? []
  const peso = c.reduce((s, x) => s + centimos(x.pesoKg), 0)
  return { ...v, cantidadEnvios: c.length, pesoCargadoKg: kg(peso) }
}
const conCarga = (v) => ({ ...resumen(v), carga: sim.cargas[v.codigo] ?? [] })
const buscar = (codigo) => sim.viajes.find((v) => v.codigo === codigo)

const { comprobar, titulo, terminar } = crearInforme()
const navegador = await iniciarNavegador(9346, ({ metodo, ruta, url, cuerpo }) => {
  if (ruta === '/api/auth/yo') return sim.sesion ? exito({ usuario: sim.sesion }) : error(401, 'no_autenticado', 'Debes iniciar sesión.')

  if (ruta === '/api/despacho/viajes' && metodo === 'GET') {
    if (sim.fallaSalidas) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    return exito({ sucursal: SUC.MOQ001, viajes: sim.viajes.map(resumen), hayMasTerminados: false })
  }
  if (ruta === '/api/despacho/envios-en-espera') {
    const programados = sim.viajes.filter((v) => v.estado === 'PROGRAMADO')
    return exito({ envios: sim.espera.map((e) => ({ ...e, viajeSugerido: programados.find((v) => v.destino.codigo === e.destino.codigo)?.codigo ?? null })), hayMas: false })
  }
  if (ruta === '/api/despacho/opciones') {
    return exito({
      vehiculos: Object.values(VEHICULOS).map((v) => ({ ...v, ocupado: v.placa === 'B7K-210' })),
      transportistas: [{ nombreUsuario: 'pmamani', nombreCompleto: 'Pedro Mamani Huanca', sucursal: SUC.AQP001 }],
      destinos: [SUC.AQP001, SUC.ILO001, SUC.LIM001],
    })
  }
  if (ruta === '/api/despacho/viajes' && metodo === 'POST') {
    if (!cuerpo.placa || !cuerpo.transportista || !cuerpo.destino) return error(422, 'datos_invalidos', 'Revisa los datos del viaje.', { destino: 'El destino debe ser otra agencia.' })
    const codigo = `VJ20261031${String(sim.contador++).padStart(2, '0')}`
    sim.viajes.push(viaje(codigo, 'PROGRAMADO', SUC[cuerpo.destino], cuerpo.placa))
    sim.cargas[codigo] = []
    return exito({ viaje: conCarga(buscar(codigo)) }, 201)
  }
  const alCargar = ruta.match(/^\/api\/despacho\/viajes\/(VJ\d{10})\/carga$/)
  if (alCargar && metodo === 'POST') {
    if (sim.cargar) return sim.cargar(alCargar[1], cuerpo)
    const v = buscar(alCargar[1]); const e = sim.espera.find((x) => x.codigoSeguimiento === cuerpo.codigoSeguimiento)
    if (!v || !e) return error(404, 'envio_no_encontrado', 'No se encontró el envío en espera en esta agencia.')
    const total = (sim.cargas[v.codigo] ?? []).reduce((s, x) => s + centimos(x.pesoKg), 0) + centimos(e.pesoKg)
    if (total > centimos(v.vehiculo.capacidadKg)) return error(409, 'capacidad_excedida', `Con este envío el viaje llevaría ${kg(total)} kg y su vehículo admite ${v.vehiculo.capacidadKg} kg.`)
    sim.cargas[v.codigo].push(carga(e.codigoSeguimiento, e.bultos, e.pesoKg, e.destino))
    sim.espera = sim.espera.filter((x) => x !== e)
    return exito({ viaje: conCarga(v) }, 201)
  }
  const alQuitar = ruta.match(/^\/api\/despacho\/viajes\/(VJ\d{10})\/carga\/(EN[A-Z0-9]{10})$/)
  if (alQuitar && metodo === 'DELETE') {
    const c = (sim.cargas[alQuitar[1]] ?? []).find((x) => x.codigoSeguimiento === alQuitar[2])
    if (!c) return error(404, 'envio_no_encontrado', 'El envío no estaba cargado en ese viaje.')
    sim.cargas[alQuitar[1]] = sim.cargas[alQuitar[1]].filter((x) => x !== c)
    sim.espera.push({ codigoSeguimiento: c.codigoSeguimiento, estado: 'REGISTRADO', bultos: c.bultos, pesoKg: c.pesoKg, destino: c.destinoFinal })
    return exito({ viaje: conCarga(buscar(alQuitar[1])) })
  }

  if (ruta === '/api/viajes' && metodo === 'GET') {
    if (sim.fallaMis) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    const f = url.searchParams.get('estado')
    const mios = sim.viajes.filter((v) => (!f || v.estado === f))
    return exito({ viajes: mios.map(resumen), hayMas: false }, 200, sim.lentoViajes && f === 'EN_RUTA' ? 1500 : 0)
  }
  const unViaje = ruta.match(/^\/api\/viajes\/(VJ\d{10})$/)
  if (unViaje) return buscar(unViaje[1]) ? exito({ viaje: conCarga(buscar(unViaje[1])) }) : error(404, 'viaje_no_encontrado', 'No se encontró el viaje.')
  const accion = ruta.match(/^\/api\/viajes\/(VJ\d{10})\/(salida|llegada)$/)
  if (accion && metodo === 'POST') {
    const v = buscar(accion[1])
    const forzada = accion[2] === 'salida' ? sim.salida : sim.llegada
    if (forzada) return forzada(v)
    if (accion[2] === 'salida') { v.estado = 'EN_RUTA'; v.fechaHoraSalida = '2026-10-31 10:00:00'; v.llegadaEstimada = '2026-10-31 14:00:00' }
    else { v.estado = 'TERMINADO'; v.fechaHoraLlegada = '2026-10-31 14:00:00'; v.llegadaEstimada = null }
    return exito({ viaje: conCarga(v) })
  }
  return null
})
const { irA, escribir, pulsar, texto, valor, existe, ruta, evaluar, llamadas } = navegador
const captura = async (nombre, ancho = 1280, alto = 800) => { if (process.env.CAPTURAS) await navegador.captura(`${process.env.CAPTURAS}/${nombre}.png`, ancho, alto) }
const llamadasA = (metodo, rutaApi) => llamadas.filter((l) => l.metodo === metodo && l.ruta === rutaApi)
const enlaces = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(a => a.innerText.trim() + '→' + a.getAttribute('href')).join(' | ')`)
const filas = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(r => r.innerText.replace(/\\s+/g,' ').trim()).join(' || ')`)
// Texto de las celdas (sin el de los <select>, cuyas opciones ensucian innerText).
const celdas = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(r => [...r.children].filter(c => !c.querySelector('select')).map(c => c.innerText.replace(/\\s+/g,' ').trim()).join(' · ')).join(' || ')`)
const deshabilitado = (selector) => evaluar(`document.querySelector(${JSON.stringify(selector)}).disabled`)
const VIAJES = '.tabla-viajes tbody tr'
const ESPERA = '.tabla-espera tbody tr'

try {
  titulo('Despacho: pantalla de la diapositiva 21')
  await irA('/administrador/despacho')
  comprobar('título con la agencia y botón «Programar viaje»', [await texto('h1'), await texto('.barra-despacho button')], ['Despacho · salen de Agencia Moquegua', 'Programar viaje'])
  comprobar('viajes: en ruta, programados y terminados con vehículo y carga', await filas(VIAJES),
    'VJ2026101402 EN RUTA Agencia Arequipa Centro FURGON B7K-210 · 18.50 / 6500 kg Ver carga || VJ2026103101 PROGRAMADO Agencia Arequipa Centro CAMIONETA C3M-956 · 0.00 / 1500 kg Cargar envíos || VJ2026103102 PROGRAMADO Agencia Arequipa Centro FURGON B7K-210 · 0.00 / 6500 kg Cargar envíos || VJ2026103103 PROGRAMADO Agencia Ilo CAMION V2A-845 · 0.00 / 18000 kg Cargar envíos')
  await evaluar(`[...document.querySelectorAll('.pestana')].find((b) => b.innerText.startsWith('Terminados')).click()`); await new Promise((r) => setTimeout(r, 300))
  comprobar('pestaña «Terminados»: el viaje terminado con su carga', await filas(VIAJES), 'VJ2026101001 TERMINADO Agencia Arequipa Centro CAMIONETA C3M-956 · 9.20 / 1500 kg Ver carga')
  await evaluar(`[...document.querySelectorAll('.pestana')].find((b) => b.innerText.startsWith('Programados')).click()`); await new Promise((r) => setTimeout(r, 300))
  comprobar('envíos en espera con su detalle', await celdas(ESPERA), 'EN4T7Q0P2LKX · 2 bultos · 9.20 kg · Agencia Arequipa Centro · Cargar en VJ2026103101 || ENQ8W2E4R6TY · 1 bulto · 3.10 kg · Agencia Arequipa Centro · Cargar en VJ2026103101 || ENA1S3D5F7GH · 1 bulto · 25.00 kg · Agencia Ilo · Cargar en VJ2026103103')
  comprobar('cada envío trae preseleccionado el viaje hacia su destino', await evaluar(`[...document.querySelectorAll('.tabla-espera select')].map(s => s.value).join(',')`), 'VJ2026103101,VJ2026103101,VJ2026103103')
  comprobar('el menú del administrador marca Despacho', (await enlaces('nav.menu a')).includes('Despacho→/administrador/despacho'), true)
  await captura('21_despacho')

  titulo('Despacho: ver y cargar')
  await pulsar(`${VIAJES}:first-child .celda-accion button`); await esperar(500)
  comprobar('«Ver carga» de un viaje en ruta: su carga, sin «Quitar»', [await texto('.carga-viaje h2'), await filas('.tabla-carga tbody tr'), await existe('.tabla-carga .celda-accion')], ['Carga de VJ2026101402', 'ENZ5H0D3V7NJ 1 18.50 kg Agencia Arequipa Centro 14/10 14:20', false])
  await pulsar(`${VIAJES}:nth-child(2) .celda-accion button`); await esperar(500)
  comprobar('«Cargar envíos» de un programado vacío: indica cómo cargar', [await texto('.carga-viaje h2'), await texto('.estado-vacio-texto')], ['Carga de VJ2026103101', 'Todavía no tiene envíos. Elígelos en «Envíos en espera en esta agencia».'])
  await pulsar(`${ESPERA}:first-child .celda-accion button`); await esperar(800)
  comprobar('se carga el envío elegido en el viaje sugerido', llamadasA('POST', '/api/despacho/viajes/VJ2026103101/carga').at(-1)?.cuerpo, { codigoSeguimiento: 'EN4T7Q0P2LKX' })
  comprobar('aviso de éxito con el peso y la capacidad', await texto('.resultado.ok'), 'Envío EN4T7Q0P2LKX cargado en VJ2026103101 (9.20 de 1500 kg).')
  comprobar('el envío sale de la espera y el viaje suma su peso', [await filas(`${VIAJES}:nth-child(2)`).then((t) => t.includes('9.20 / 1500 kg')), (await celdas(ESPERA)).startsWith('ENQ8W2E4R6TY')], [true, true])
  comprobar('el panel abierto muestra la carga nueva con «Quitar»', [await filas('.tabla-carga tbody tr'), await texto('.tabla-carga .celda-accion button')], ['EN4T7Q0P2LKX 2 9.20 kg Agencia Arequipa Centro 14/10 14:20 Quitar', 'Quitar'])
  await escribir('.tabla-espera tbody tr:nth-child(2) select', 'VJ2026103102'); await esperar(150)
  comprobar('se puede elegir otro viaje', await texto(`${ESPERA}:nth-child(2) .celda-accion button`), 'Cargar en VJ2026103102')

  titulo('Despacho: capacidad y errores del servidor')
  sim.viajes[1].vehiculo = { ...VEHICULOS['C3M-956'], capacidadKg: '10.00' } // la camioneta ahora admite solo 10 kg
  await irA('/administrador/despacho')
  await pulsar(`${ESPERA}:first-child .celda-accion button`); await esperar(700) // 9.20 + 3.10 > 10.00
  comprobar('409 por capacidad: el aviso dice las cifras y nada cambia', [await texto('.resultado.error'), (await celdas(ESPERA)).includes('ENQ8W2E4R6TY')], ['Con este envío el viaje llevaría 12.30 kg y su vehículo admite 10.00 kg.', true])
  sim.viajes[1].vehiculo = VEHICULOS['C3M-956']
  sim.cargar = () => error(409, 'envio_ya_cargado', 'El envío ya está cargado en un viaje que todavía no llegó.')
  await pulsar(`${ESPERA}:first-child .celda-accion button`); await esperar(700)
  comprobar('409 por carrera (otra caja lo cargó): aviso y se recarga la lista', await texto('.resultado.error'), 'El envío ya está cargado en un viaje que todavía no llegó.')
  sim.cargar = null

  titulo('Despacho: quitar un envío (con confirmación)')
  await pulsar(`${VIAJES}:nth-child(2) .celda-accion button`); await esperar(500)
  await pulsar('.tabla-carga .celda-accion button'); await esperar(150)
  comprobar('pide confirmación antes de quitar', [await texto('.tabla-carga .confirmar-cancelacion'), llamadasA('DELETE', '/api/despacho/viajes/VJ2026103101/carga/EN4T7Q0P2LKX').length], ['¿Quitar EN4T7Q0P2LKX? Sí, quitar No', 0])
  await pulsar('.tabla-carga .confirmar-cancelacion .boton-claro'); await esperar(150)
  comprobar('«No» cancela', [await existe('.confirmar-cancelacion'), llamadasA('DELETE', '/api/despacho/viajes/VJ2026103101/carga/EN4T7Q0P2LKX').length], [false, 0])
  await pulsar('.tabla-carga .celda-accion button'); await pulsar('.tabla-carga .confirmar-cancelacion .boton-marino'); await esperar(900)
  comprobar('quitado: vuelve a esperar y el viaje queda sin carga', [await texto('.resultado.ok'), (await celdas(ESPERA)).includes('EN4T7Q0P2LKX'), await texto('.estado-vacio-texto')],
    ['Envío EN4T7Q0P2LKX quitado de VJ2026103101: vuelve a esperar en la agencia.', true, 'Todavía no tiene envíos. Elígelos en «Envíos en espera en esta agencia».'])
  await pulsar('.carga-viaje-cabecera button'); await esperar(200)
  comprobar('«Cerrar» oculta el panel', await existe('.carga-viaje'), false)

  titulo('Despacho: programar viaje')
  await pulsar('.barra-despacho button'); await esperar(600)
  comprobar('el formulario ofrece vehículos (con el ocupado marcado), transportistas y destinos sin la propia agencia', await evaluar(`['placa','transportista','destino'].map(id => [...document.querySelectorAll('#' + id + ' option')].map(o => o.innerText.trim()).join(' / ')).join(' || ')`),
    'Elige… / CAMIONETA C3M-956 · 1500 kg / FURGON B7K-210 · 6500 kg · en ruta / CAMION V2A-845 · 18000 kg || Elige… / Pedro Mamani Huanca (AQP001) || Elige… / Agencia Arequipa Centro / Agencia Ilo / Agencia Lima Centro')
  await pulsar('.programar-viaje button[type=submit]'); await esperar(200)
  comprobar('vacío: tres errores y no llama a la API', [await evaluar(`[...document.querySelectorAll('.programar-viaje .campo-error')].map(e => e.innerText.trim()).join(' | ')`), llamadasA('POST', '/api/despacho/viajes').length], ['Elige un vehículo. | Elige un transportista. | Elige la agencia de destino.', 0])
  await escribir('#placa', 'V2A-845'); await escribir('#transportista', 'pmamani'); await escribir('#destino', 'LIM001')
  await pulsar('.programar-viaje button[type=submit]'); await esperar(900)
  comprobar('se programa con lo elegido (sin origen: lo pone el servidor)', llamadasA('POST', '/api/despacho/viajes').at(-1)?.cuerpo, { placa: 'V2A-845', transportista: 'pmamani', destino: 'LIM001' })
  comprobar('aviso, formulario cerrado y el viaje nuevo en la lista', [await texto('.resultado.ok'), await existe('.programar-viaje'), (await filas(VIAJES)).includes('VJ2026103104 PROGRAMADO Agencia Lima Centro CAMION V2A-845 · 0.00 / 18000 kg Cargar envíos')], ['Viaje VJ2026103104 programado hacia Agencia Lima Centro.', false, true])

  titulo('Despacho: sin viajes programados, vacío y errores')
  sim.viajes = sim.viajes.filter((v) => v.estado !== 'PROGRAMADO')
  await irA('/administrador/despacho')
  comprobar('sin viajes programados: aviso y no se puede cargar', [await texto('.tabla-envoltorio + .mensaje-aviso, .mensaje-aviso'), await deshabilitado(`${ESPERA}:first-child .celda-accion button`)], ['No hay viajes programados: programa uno para poder cargar estos envíos.', true])
  sim.viajes = []; sim.espera = []
  await irA('/administrador/despacho')
  comprobar('sin viajes ni envíos: dos estados vacíos', await evaluar(`[...document.querySelectorAll('.estado-vacio')].map(e => e.innerText.trim()).join(' | ')`), 'Esta agencia todavía no tiene viajes. Programa el primero. | No hay envíos esperando en esta agencia.')
  sim.fallaSalidas = true
  await irA('/administrador/despacho')
  comprobar('si falla la lista de viajes: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudieron cargar los viajes. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallaSalidas = false; reiniciar()
  await pulsar('.resultado.error button'); await esperar(700)
  comprobar('«Reintentar» recarga', (await filas(VIAJES)).startsWith('VJ2026101402'), true)

  titulo('Mis viajes: pantalla de la diapositiva 24')
  sim.sesion = TRANSP
  await irA('/transportista')
  comprobar('título y filas con salida, llegada o «Sin salida aún»', [await texto('h1'), await celdas(VIAJES)], ['Mis viajes',
'VJ2026101402 · Agencia Moquegua → Agencia Arequipa Centro Agencia Moquegua a Agencia Arequipa Centro · Salió 14/10 15:00 · EN RUTA · Abrir · marcar llegada || VJ2026103101 · Agencia Moquegua → Agencia Arequipa Centro Agencia Moquegua a Agencia Arequipa Centro · Sin salida aún · PROGRAMADO · Abrir · marcar salida || VJ2026103102 · Agencia Moquegua → Agencia Arequipa Centro Agencia Moquegua a Agencia Arequipa Centro · Sin salida aún · PROGRAMADO · Abrir · marcar salida || VJ2026103103 · Agencia Moquegua → Agencia Ilo Agencia Moquegua a Agencia Ilo · Sin salida aún · PROGRAMADO · Abrir · marcar salida || VJ2026101001 · Agencia Moquegua → Agencia Arequipa Centro Agencia Moquegua a Agencia Arequipa Centro · Llegó 10/10 18:30 · TERMINADO · Ver detalle'])
  comprobar('los filtros', await texto('.filtros'), 'Todos Programados En ruta Terminados')
  comprobar('«Abrir» lleva al detalle del viaje', (await enlaces(`${VIAJES} a`)).split(' | ')[0], 'Abrir · marcar llegada→/transportista/viajes/VJ2026101402')
  await pulsar('.filtros button:nth-child(3)'); await esperar(500)
  comprobar('filtro «En ruta»: consulta con el estado y muestra solo ese viaje', [llamadasA('GET', '/api/viajes').at(-1) !== undefined, (await filas(VIAJES)).startsWith('VJ2026101402') && (await evaluar(`document.querySelectorAll('${VIAJES}').length`)) === 1], [true, true])
  comprobar('el filtro activo queda marcado', await evaluar(`document.querySelector('.filtro.activo').innerText`), 'En ruta')
  await pulsar('.filtros button:nth-child(4)'); await esperar(400)
  comprobar('filtro «Terminados»', (await celdas(VIAJES)).startsWith('VJ2026101001'), true)
  await captura('24_mis_viajes')

  titulo('Mis viajes: carrera de filtros, vacío y error')
  sim.lentoViajes = true
  await pulsar('.filtros button:nth-child(3)'); await esperar(200)
  await pulsar('.filtros button:nth-child(2)'); await esperar(500)
  comprobar('se muestra el último filtro elegido (Programados)', (await celdas(VIAJES)).startsWith('VJ2026103101'), true)
  await esperar(1500)
  comprobar('la respuesta tardía del filtro anterior no la pisa', [(await celdas(VIAJES)).startsWith('VJ2026103101'), await evaluar(`document.querySelector('.filtro.activo').innerText`)], [true, 'Programados'])
  sim.lentoViajes = false
  sim.viajes = []
  await irA('/transportista')
  comprobar('sin viajes: mensaje', await texto('.estado-vacio'), 'Todavía no tienes viajes asignados.')
  sim.fallaMis = true
  await irA('/transportista')
  comprobar('si falla: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudieron cargar tus viajes. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallaMis = false; reiniciar()
  await pulsar('.resultado.error button'); await esperar(600)
  comprobar('«Reintentar» recarga', (await celdas(VIAJES)).startsWith('VJ2026101402'), true)

  titulo('Detalle del viaje: pantalla de la diapositiva 25')
  await irA('/transportista/viajes/VJ2026101402')
  comprobar('cabecera con ruta y estado', [await texto('h1'), await texto('.detalle-viaje-cabecera .insignia')], ['VJ2026101402 · Agencia Moquegua → Agencia Arequipa Centro', 'EN RUTA'])
  comprobar('vehículo, salida y llegada estimada (— (estimada 19:00))', await filas('.datos-viaje > div'), 'Vehículo FURGON B7K-210 || Salida 14/10 15:00 || Llegada — (estimada 19:00)')
  comprobar('carga y peso cargado (18.50 de 6500 kg)', [await filas('.tabla-carga tbody tr'), await texto('.peso-cargado')], ['ENZ5H0D3V7NJ 1 18.50 kg Agencia Arequipa Centro 14/10 14:20', 'Peso cargado 18.50 de 6500 kg'])
  comprobar('en ruta: «Marcar salida» deshabilitado y «Marcar llegada» disponible', [await deshabilitado('.acciones-formulario .boton-azul'), await deshabilitado('.acciones-formulario .boton-marino')], [true, false])
  await captura('25_detalle_viaje')

  titulo('Detalle del viaje: marcar la llegada (con confirmación)')
  await pulsar('.acciones-formulario .boton-marino'); await esperar(200)
  comprobar('pide confirmación y no llama a la API', [await texto('.confirmar-viaje p'), llamadasA('POST', '/api/viajes/VJ2026101402/llegada').length], ['¿Marcar la llegada ahora? Quedará registrada con la hora actual y no se puede deshacer.', 0])
  await pulsar('.confirmar-viaje .boton-claro'); await esperar(150)
  comprobar('«No» vuelve a los botones', [await existe('.confirmar-viaje'), llamadasA('POST', '/api/viajes/VJ2026101402/llegada').length], [false, 0])
  await pulsar('.acciones-formulario .boton-marino'); await pulsar('.confirmar-viaje .boton-azul'); await esperar(800)
  comprobar('llegada registrada: estado TERMINADO, hora y botones apagados', [await texto('.detalle-viaje-cabecera .insignia'), (await filas('.datos-viaje > div')).includes('Llegada 31/10 14:00'), await deshabilitado('.acciones-formulario .boton-azul'), await deshabilitado('.acciones-formulario .boton-marino')], ['TERMINADO', true, true, true])
  comprobar('aviso de éxito', await texto('.resultado.ok'), 'Llegada registrada. Los envíos quedaron disponibles en la agencia de destino.')

  titulo('Detalle del viaje: marcar la salida y errores')
  await irA('/transportista/viajes/VJ2026103101')
  comprobar('programado: «Marcar salida» disponible y «Marcar llegada» apagado; sin carga', [await deshabilitado('.acciones-formulario .boton-azul'), await deshabilitado('.acciones-formulario .boton-marino'), await texto('.estado-vacio')], [false, true, 'Este viaje no tiene envíos cargados.'])
  await pulsar('.acciones-formulario .boton-azul'); await esperar(200)
  comprobar('un viaje sin carga lo advierte antes de confirmar', (await texto('.confirmar-viaje p')).endsWith('Este viaje sale sin carga.'), true)
  sim.salida = () => error(409, 'vehiculo_ocupado', 'El vehículo tiene otro viaje en ruta: debe terminar antes de que salga este.')
  await pulsar('.confirmar-viaje .boton-azul'); await esperar(800)
  comprobar('409 vehículo ocupado: aviso, vuelve a los botones y el estado no cambia', [await texto('.resultado.error'), await texto('.detalle-viaje-cabecera .insignia'), await existe('.confirmar-viaje')], ['El vehículo tiene otro viaje en ruta: debe terminar antes de que salga este.', 'PROGRAMADO', false])
  sim.salida = null
  await pulsar('.acciones-formulario .boton-azul'); await pulsar('.confirmar-viaje .boton-azul'); await esperar(800)
  comprobar('salida registrada: EN RUTA con la hora y la llegada estimada', [await texto('.detalle-viaje-cabecera .insignia'), await filas('.datos-viaje > div')], ['EN RUTA', 'Vehículo CAMIONETA C3M-956 || Salida 31/10 10:00 || Llegada — (estimada 14:00)'])
  sim.llegada = () => error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
  await pulsar('.acciones-formulario .boton-marino'); await pulsar('.confirmar-viaje .boton-azul'); await esperar(800)
  comprobar('error interno al marcar la llegada: aviso y el botón se reactiva', [await texto('.resultado.error'), await deshabilitado('.acciones-formulario .boton-marino')], ['Ocurrió un error interno. Inténtalo de nuevo más tarde.', false])
  sim.llegada = null
  await irA('/transportista/viajes/VJ2026109999')
  comprobar('viaje ajeno o inexistente', [await texto('h1'), await texto('.confirmacion p')], ['No encontramos ese viaje', 'No se encontró el viaje.'])

  if (process.env.CAPTURAS) {
    // Revisión en móvil: nada debe obligar a desplazarse de lado.
    titulo('Móvil (solo con CAPTURAS)')
    reiniciar()
    for (const [sesion, pantalla, nombre] of [[ADMIN, '/administrador/despacho', 'm_despacho'], [TRANSP, '/transportista', 'm_mis_viajes'], [TRANSP, '/transportista/viajes/VJ2026101402', 'm_detalle_viaje']]) {
      sim.sesion = sesion
      await irA(pantalla)
      await captura(nombre, 390, 844)
      comprobar(`${nombre}: sin desplazamiento horizontal`, await evaluar('document.documentElement.scrollWidth <= window.innerWidth'), true)
    }
  }

  titulo('Accesos por rol')
  sim.sesion = ADMIN
  await irA('/transportista')
  comprobar('un administrador no entra a Mis viajes (lo manda a Ventanilla)', await ruta(), '/administrador')
  sim.sesion = TRANSP
  await irA('/administrador/despacho')
  comprobar('un transportista no entra a Despacho (lo manda a Mis viajes)', await ruta(), '/transportista')
  sim.sesion = null
  await irA('/transportista/viajes/VJ2026101402')
  comprobar('sin sesión: a Ingresar', (await ruta()).startsWith('/ingresar'), true)
} finally {
  await navegador.cerrar()
}
terminar()
