// Pruebas de las pantallas del módulo 6 en un navegador real: Caja (Administrador, diapositiva 20) y Mis pagos
// (Cliente, diapositiva 16). La API está interceptada (sin PHP ni base de datos) y algunas respuestas se retrasan a
// propósito para reproducir carreras asíncronas.
//
// Uso:  1) en frontend/:  npm run dev      2) desde C:\Jatsa:  node frontend/tests/e2e/caja.mjs
import { crearInforme, esperar, iniciarNavegador } from './arnes.mjs'

const RETRASO_MS = 1500
const exito = (datos, estado = 200, demoraMs = 0) => ({ estado, cuerpo: { data: datos }, demoraMs })
const error = (estado, codigo, mensaje, campos) => ({ estado, cuerpo: { error: { codigo, mensaje, ...(campos ? { campos } : {}) } } })

const MOQ = { codigo: 'MOQ001', nombre: 'Agencia Moquegua' }
const AQP = { codigo: 'AQP001', nombre: 'Agencia Arequipa Centro' }
const ADMIN = { id: 2, nombreUsuario: 'lflores', nombreCompleto: 'Luis Flores', rol: 'ADMINISTRADOR', sucursal: { id: 3, ...MOQ } }
const CLIENTE = { id: 4, nombreUsuario: 'mrojas', nombreCompleto: 'María Elena Rojas Ccori', rol: 'CLIENTE', sucursal: null }

const centimos = (texto) => Math.round(Number(String(texto).replace(',', '.')) * 100)
const soles = (c) => `${Math.floor(c / 100)}.${String(c % 100).padStart(2, '0')}`
const pago = (id, fechaHora, codigoSeguimiento, monto, metodoPago, pagadoPor, estado = 'REGISTRADO', sucursal = MOQ) => ({ id, fechaHora, codigoSeguimiento, monto, metodoPago, pagadoPor, estado, sucursal })

const sim = { sesion: ADMIN, envios: {}, pagos: [], siguientePago: 6, buscarLento: null, registrar: null, fallaLista: false, fallaMis: false, misPagos: [], misTotal: '32.25' }

function reiniciar() {
  sim.envios = {
    EN4T7Q0P2LKX: { importe: '23.75' }, // el envío de la diapositiva 20, sin pagos
    ENDESTINO001: { importe: '8.50', sugerido: 'DESTINATARIO' }, // se cobra en la agencia de destino
    ENZ5H0D3V7NJ: { importe: '14.60' }, // ya pagado por completo con el pago 5
    ENANULADO000: { importe: '9.15', estado: 'ANULADO' },
  }
  // Los tres pagos «cobrados aquí» de la diapositiva 20.
  sim.pagos = [
    pago(5, '2026-10-14 14:05:00', 'ENZ5H0D3V7NJ', '14.60', 'TARJETA', 'REMITENTE'),
    pago(4, '2026-10-13 12:10:00', 'ENM2P6T8R1WQ', '8.50', 'EFECTIVO', 'DESTINATARIO'),
    pago(1, '2026-10-10 10:04:00', 'EN7K4Q2M9X1B', '23.75', 'EFECTIVO', 'REMITENTE'),
  ]
  sim.pagos.sort((a, b) => (a.fechaHora < b.fechaHora ? 1 : -1))
  sim.misTotal = '32.25'
  sim.misPagos = [
    pago(4, '2026-10-13 12:10:00', 'ENM2P6T8R1WQ', '8.50', 'EFECTIVO', 'DESTINATARIO'),
    pago(1, '2026-10-10 10:04:00', 'EN7K4Q2M9X1B', '23.75', 'EFECTIVO', 'REMITENTE'),
  ]
}
reiniciar()

function envioCobro(codigo) {
  const e = sim.envios[codigo]
  if (!e) return null
  const propios = sim.pagos.filter((p) => p.codigoSeguimiento === codigo)
  const pagado = propios.filter((p) => p.estado === 'REGISTRADO').reduce((suma, p) => suma + centimos(p.monto), 0)
  return {
    codigoSeguimiento: codigo, estado: e.estado ?? 'REGISTRADO', origen: MOQ, destino: AQP, remitente: 'María Elena Rojas Ccori', destinatario: 'Luis Alberto Flores Condori',
    importe: e.importe, pagado: soles(pagado), saldo: soles(Math.max(0, centimos(e.importe) - pagado)), pagadoPorSugerido: e.sugerido ?? 'REMITENTE', pagos: propios,
  }
}

function totales() {
  const t = { EFECTIVO: 0, TARJETA: 0, BILLETERA: 0, TRANSFERENCIA: 0 }
  for (const p of sim.pagos) if (p.estado === 'REGISTRADO') t[p.metodoPago] += centimos(p.monto)
  const total = Object.values(t).reduce((a, b) => a + b, 0)
  return { EFECTIVO: soles(t.EFECTIVO), TARJETA: soles(t.TARJETA), BILLETERA: soles(t.BILLETERA), TRANSFERENCIA: soles(t.TRANSFERENCIA), total: soles(total) }
}

const { comprobar, titulo, terminar } = crearInforme()
const navegador = await iniciarNavegador(9345, ({ metodo, ruta, cuerpo }) => {
  if (ruta === '/api/auth/yo') return sim.sesion ? exito({ usuario: sim.sesion }) : error(401, 'no_autenticado', 'Debes iniciar sesión.')

  const unEnvio = ruta.match(/^\/api\/caja\/envios\/(EN[A-Z0-9]{10})$/)
  if (unEnvio) {
    const e = envioCobro(unEnvio[1])
    return e ? exito({ envio: e }, 200, sim.buscarLento === unEnvio[1] ? RETRASO_MS : 0) : error(404, 'envio_no_encontrado', 'No se encontró el envío.')
  }
  if (ruta === '/api/caja/pagos' && metodo === 'GET') {
    if (sim.fallaLista) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    return exito({ sucursal: MOQ, pagos: sim.pagos, hayMas: false, totales: totales() })
  }
  if (ruta === '/api/caja/pagos' && metodo === 'POST') {
    if (sim.registrar) return sim.registrar(cuerpo)
    const e = envioCobro(cuerpo.codigoSeguimiento)
    if (!e) return error(404, 'envio_no_encontrado', 'No se encontró el envío.')
    if (centimos(cuerpo.monto) > centimos(e.saldo)) return error(422, 'datos_invalidos', 'Revisa los datos del pago.', { monto: `El monto supera el saldo del envío (S/ ${e.saldo}).` })
    const nuevo = pago(sim.siguientePago++, '2026-10-31 10:00:00', cuerpo.codigoSeguimiento, soles(centimos(cuerpo.monto)), cuerpo.metodoPago, cuerpo.pagadoPor)
    sim.pagos.unshift(nuevo)
    return exito({ pago: nuevo, envio: envioCobro(cuerpo.codigoSeguimiento) }, 201)
  }
  const anular = ruta.match(/^\/api\/caja\/pagos\/(\d+)\/anular$/)
  if (anular && metodo === 'POST') {
    const p = sim.pagos.find((x) => String(x.id) === anular[1])
    if (!p) return error(404, 'pago_no_encontrado', 'No se encontró el pago en esta caja.')
    if (p.estado === 'ANULADO') return error(409, 'pago_anulado', 'El pago ya estaba anulado.')
    p.estado = 'ANULADO'
    return exito({ pago: p })
  }
  if (ruta === '/api/pagos') {
    if (sim.fallaMis) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    return exito({ pagos: sim.misPagos, hayMas: false, totalPagado: sim.misTotal })
  }
  return null
})
const { irA, escribir, pulsar, texto, valor, existe, ruta, evaluar, llamadas } = navegador
const captura = async (nombre, ancho = 1280, alto = 800) => { if (process.env.CAPTURAS) await navegador.captura(`${process.env.CAPTURAS}/${nombre}.png`, ancho, alto) }
const llamadasA = (metodo, rutaApi) => llamadas.filter((l) => l.metodo === metodo && l.ruta === rutaApi)
const enlaces = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(a => a.innerText.trim() + '→' + a.getAttribute('href')).join(' | ')`)
const filas = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(r => r.innerText.replace(/\\s+/g,' ').trim()).join(' || ')`)
const metodoElegido = () => evaluar(`document.querySelector('input[name=metodoPago]:checked')?.value ?? null`)
const BTN_REGISTRAR = '.formulario-pago button[type=submit]'
const TOTALES = '.totales-caja p'
const FILAS = '.tabla-pagos tbody tr'

async function buscar(codigo) {
  await escribir('#codigoEnvio', codigo)
  await pulsar('.buscar-reserva button')
  await esperar(500)
}

try {
  titulo('Caja: pantalla de la diapositiva 20')
  await irA('/administrador/caja')
  comprobar('título con el nombre de la agencia', await texto('h1'), 'Caja · Agencia Moquegua')
  comprobar('«Registrar pago» con el campo Envío y sin formulario hasta cargar un envío', [await texto('.registrar-pago h2'), await existe('#codigoEnvio'), await existe('.formulario-pago')], ['Registrar pago', true, false])
  comprobar('pagos cobrados aquí: más reciente primero, con «Anular» en los registrados', await filas(FILAS),
    '14/10 14:05 ENZ5H0D3V7NJ 14.60 TARJETA REGISTRADO No Anular || 13/10 12:10 ENM2P6T8R1WQ 8.50 EFECTIVO REGISTRADO No Anular || 10/10 10:04 EN7K4Q2M9X1B 23.75 EFECTIVO REGISTRADO No Anular')
  comprobar('totales por método (solo los que tienen cobros) y total', await filas(TOTALES), 'S/ 32.25 Efectivo || S/ 14.60 Tarjeta || S/ 46.85 Total')
  comprobar('el menú marca la Caja y los accesos del administrador siguen', await enlaces('nav.menu a'), 'Ventanilla→/administrador | Caja→/administrador/caja | Despacho→/administrador/despacho | Llegadas y entregas→/administrador/llegadas-entregas | Reportes→/administrador/reportes | Mis encomiendas→/cliente')
  await captura('20_caja')

  titulo('Caja: buscar el envío')
  await buscar('EN12')
  comprobar('un código mal escrito se explica y no llama a la API', [await texto('.resultado.error'), llamadasA('GET', '/api/caja/envios/EN12').length], ['El código de seguimiento empieza con EN y tiene 12 caracteres (por ejemplo, EN4T7Q0P2LKX).', 0])
  await buscar('ENZZZZZZZZZZ')
  comprobar('envío inexistente o de otra agencia: mensaje del servidor', [await texto('.resultado.error'), await existe('.formulario-pago')], ['No se encontró el envío.', false])
  await buscar(' en4t7q0p2lkx ')
  comprobar('encuentra el envío (acepta minúsculas y espacios): importe, pagado y saldo', await texto('.resumen-cobro'), 'Importe S/ 23.75 Pagado S/ 0.00 Saldo S/ 23.75')
  comprobar('el formulario arranca con el saldo, REMITENTE y EFECTIVO', [await valor('#monto'), await valor('#pagadoPor'), await metodoElegido()], ['23.75', 'REMITENTE', 'EFECTIVO'])
  comprobar('los cuatro métodos de pago', await evaluar(`[...document.querySelectorAll('input[name=metodoPago]')].map(i => i.value).join(',')`), 'EFECTIVO,TARJETA,BILLETERA,TRANSFERENCIA')
  comprobar('el aviso anterior desaparece al encontrar el envío', await existe('.resultado.error'), false)
  await captura('20_caja_cobrando')

  titulo('Caja: registrar pagos')
  await escribir('#monto', '30.00'); await pulsar(BTN_REGISTRAR); await esperar(300)
  comprobar('monto mayor que el saldo: error en el campo y sin llamar a la API', [await texto('#monto-error'), llamadasA('POST', '/api/caja/pagos').length], ['El monto supera el saldo del envío (S/ 23.75).', 0])
  for (const malo of ['abc', '0', '1.234', '']) {
    await escribir('#monto', malo); await pulsar(BTN_REGISTRAR); await esperar(150)
  }
  comprobar('montos inválidos (texto, cero, 3 decimales, vacío) tampoco llaman a la API', llamadasA('POST', '/api/caja/pagos').length, 0)
  await escribir('#monto', '10,00'); await pulsar('#metodo-TARJETA'); await escribir('#pagadoPor', 'DESTINATARIO'); await pulsar(BTN_REGISTRAR); await esperar(700)
  comprobar('pago parcial: se envía lo elegido (coma decimal incluida)', llamadasA('POST', '/api/caja/pagos').at(-1)?.cuerpo, { codigoSeguimiento: 'EN4T7Q0P2LKX', monto: '10,00', pagadoPor: 'DESTINATARIO', metodoPago: 'TARJETA' })
  comprobar('aviso de éxito con el saldo restante', await texto('.resultado.ok'), 'Pago de S/ 10.00 registrado (tarjeta). Saldo: S/ 13.75.')
  comprobar('el resumen se actualiza y el monto vuelve a ser el saldo', [await texto('.resumen-cobro'), await valor('#monto')], ['Importe S/ 23.75 Pagado S/ 10.00 Saldo S/ 13.75', '13.75'])
  comprobar('la lista de cobrados se recarga con el pago nuevo arriba', (await filas(FILAS)).split(' || ')[0], '31/10 10:00 EN4T7Q0P2LKX 10.00 TARJETA REGISTRADO No Anular')
  comprobar('los totales incluyen el pago nuevo', await filas(TOTALES), 'S/ 32.25 Efectivo || S/ 24.60 Tarjeta || S/ 56.85 Total')
  await pulsar(BTN_REGISTRAR); await esperar(700)
  comprobar('el resto del saldo se cobra con el método elegido', llamadasA('POST', '/api/caja/pagos').at(-1)?.cuerpo.monto, '13.75')
  comprobar('envío pagado por completo: aviso y el formulario desaparece', [await texto('.resumen-cobro'), await existe('.formulario-pago'), await texto('.mensaje-aviso')], ['Importe S/ 23.75 Pagado S/ 23.75 Saldo S/ 0.00', false, 'Este envío ya está pagado por completo.'])
  comprobar('el aviso de éxito dice que quedó pagado', await texto('.resultado.ok'), 'Pago de S/ 13.75 registrado (tarjeta). El envío quedó pagado por completo.')

  titulo('Caja: anular un pago (no se borra)')
  await pulsar(`${FILAS}:first-child .celda-accion button`); await esperar(200)
  comprobar('pide confirmación antes de anular', [await texto(`${FILAS}:first-child .confirmar-cancelacion`), llamadasA('POST', '/api/caja/pagos/7/anular').length], ['¿Anular S/ 13.75? Sí, anular No', 0])
  await pulsar(`${FILAS}:first-child .confirmar-cancelacion .boton-claro`); await esperar(200)
  comprobar('«No» cancela la anulación', [await existe('.confirmar-cancelacion'), llamadasA('POST', '/api/caja/pagos/7/anular').length], [false, 0])
  await pulsar(`${FILAS}:first-child .celda-accion button`); await pulsar(`${FILAS}:first-child .confirmar-cancelacion .boton-marino`); await esperar(800)
  comprobar('anulado: la fila sigue, con estado ANULADO y sin botón', (await filas(FILAS)).split(' || ')[0], '31/10 10:00 EN4T7Q0P2LKX 13.75 TARJETA ANULADO No')
  comprobar('aviso de anulación', await texto('.resultado.ok'), 'Pago de S/ 13.75 del envío EN4T7Q0P2LKX anulado.')
  comprobar('el envío abierto recupera su saldo y vuelve el formulario', [await texto('.resumen-cobro'), await existe('.formulario-pago')], ['Importe S/ 23.75 Pagado S/ 10.00 Saldo S/ 13.75', true])
  comprobar('el pago anulado ya no suma en los totales', await filas(TOTALES), 'S/ 32.25 Efectivo || S/ 24.60 Tarjeta || S/ 56.85 Total')

  titulo('Caja: errores del servidor')
  sim.registrar = () => error(409, 'envio_pagado', 'El envío ya está pagado por completo.')
  await pulsar(BTN_REGISTRAR); await esperar(800)
  comprobar('409: aviso del servidor y se vuelve a leer el envío', [await texto('.resultado.error'), await existe('.formulario-pago')], ['El envío ya está pagado por completo.', true])
  sim.registrar = () => error(422, 'datos_invalidos', 'Revisa los datos del pago.', { monto: 'El monto supera el saldo del envío (S/ 5.00).' })
  await pulsar(BTN_REGISTRAR); await esperar(800)
  comprobar('422 por saldo cambiado: error en el campo (otra caja cobró)', await texto('#monto-error'), 'El monto supera el saldo del envío (S/ 5.00).')
  sim.registrar = () => error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
  await pulsar(BTN_REGISTRAR); await esperar(600)
  comprobar('error interno: aviso y el botón se reactiva', [await texto('.resultado.error'), await evaluar(`document.querySelector(${JSON.stringify(BTN_REGISTRAR)}).disabled`)], ['Ocurrió un error interno. Inténtalo de nuevo más tarde.', false])
  sim.registrar = null

  titulo('Caja: pagos en destino, envío pagado y anulado')
  await buscar('ENDESTINO001')
  comprobar('en la agencia de destino se propone que pague el DESTINATARIO', [await texto('.resumen-cobro'), await valor('#pagadoPor')], ['Importe S/ 8.50 Pagado S/ 0.00 Saldo S/ 8.50', 'DESTINATARIO'])
  await buscar('ENZ5H0D3V7NJ')
  comprobar('un envío ya pagado no admite otro cobro', [await texto('.mensaje-aviso'), await existe('.formulario-pago')], ['Este envío ya está pagado por completo.', false])
  await buscar('ENANULADO000')
  comprobar('un envío anulado no admite pagos', [await texto('.mensaje-aviso'), await existe('.formulario-pago')], ['El envío está anulado: no admite pagos.', false])

  titulo('Caja: llegar desde «Cobrar ahora» (?envio=)')
  await irA('/administrador/caja?envio=ENDESTINO001')
  comprobar('el envío llega cargado sin escribir nada', [await valor('#codigoEnvio'), await texto('.resumen-cobro'), await valor('#monto')], ['ENDESTINO001', 'Importe S/ 8.50 Pagado S/ 0.00 Saldo S/ 8.50', '8.50'])

  titulo('Caja: dos búsquedas seguidas (carrera)')
  sim.buscarLento = 'EN4T7Q0P2LKX'
  await irA('/administrador/caja')
  await escribir('#codigoEnvio', 'EN4T7Q0P2LKX'); await pulsar('.buscar-reserva button')
  await esperar(300)
  await escribir('#codigoEnvio', 'ENDESTINO001'); await pulsar('.buscar-reserva button')
  await esperar(500)
  comprobar('se muestra la última búsqueda', await texto('.resumen-cobro'), 'Importe S/ 8.50 Pagado S/ 0.00 Saldo S/ 8.50')
  await esperar(RETRASO_MS + 300)
  comprobar('la respuesta tardía de la búsqueda anterior no la pisa', await texto('.resumen-cobro'), 'Importe S/ 8.50 Pagado S/ 0.00 Saldo S/ 8.50')
  sim.buscarLento = null

  titulo('Caja: estados vacío y de error de la lista')
  sim.pagos = []
  await irA('/administrador/caja')
  comprobar('caja sin cobros: aviso y total en cero', [await texto('.estado-vacio'), await filas(TOTALES)], ['Todavía no se cobró ningún pago en esta caja.', 'S/ 0.00 Total'])
  sim.fallaLista = true
  await irA('/administrador/caja')
  comprobar('si falla la lista: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudieron cargar los pagos. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallaLista = false; reiniciar()
  await pulsar('.resultado.error button'); await esperar(600)
  comprobar('«Reintentar» recarga la lista', (await filas(FILAS)).startsWith('14/10 14:05 ENZ5H0D3V7NJ'), true)

  titulo('Mis pagos: pantalla de la diapositiva 16')
  sim.sesion = CLIENTE
  await irA('/cliente/pagos')
  comprobar('título y filas con fecha, envío, monto, método, pagador, sucursal y estado', [await texto('h1'), await filas(FILAS)],
    ['Mis pagos', '13/10 12:10 ENM2P6T8R1WQ S/ 8.50 EFECTIVO DESTINATARIO Agencia Moquegua REGISTRADO Ver envío || 10/10 10:04 EN7K4Q2M9X1B S/ 23.75 EFECTIVO REMITENTE Agencia Moquegua REGISTRADO Ver envío'])
  comprobar('total pagado', await texto('.total-pagado'), 'Total pagado S/ 32.25')
  comprobar('«Ver envío» lleva al rastreo del envío', (await enlaces(`${FILAS} a`)).split(' | ')[0], 'Ver envío→/rastreo?codigo=ENM2P6T8R1WQ')
  comprobar('el menú del cliente marca Mis pagos', (await enlaces('nav.menu a')).includes('Mis pagos→/cliente/pagos'), true)
  await captura('16_mis_pagos')
  sim.misPagos = []; sim.misTotal = '0.00'
  await irA('/cliente/pagos')
  comprobar('sin pagos: estado vacío con acceso a reservar', [await texto('.estado-vacio p'), await existe('.total-pagado')], ['Todavía no tienes pagos registrados.', false])
  sim.fallaMis = true
  await irA('/cliente/pagos')
  comprobar('si falla: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudieron cargar tus pagos. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallaMis = false; reiniciar()
  await pulsar('.resultado.error button'); await esperar(600)
  comprobar('«Reintentar» recarga los pagos', await texto('.total-pagado'), 'Total pagado S/ 32.25')

  if (process.env.CAPTURAS) {
    // Revisión en móvil: nada debe obligar a desplazarse de lado.
    titulo('Móvil (solo con CAPTURAS)')
    for (const [sesion, pantalla, nombre] of [[CLIENTE, '/cliente/pagos', 'm_mis_pagos'], [ADMIN, '/administrador/caja?envio=EN4T7Q0P2LKX', 'm_caja']]) {
      sim.sesion = sesion
      await irA(pantalla)
      await captura(nombre, 390, 844)
      comprobar(`${nombre}: sin desplazamiento horizontal`, await evaluar('document.documentElement.scrollWidth <= window.innerWidth'), true)
    }
  }

  titulo('Accesos por rol')
  sim.sesion = CLIENTE
  await irA('/administrador/caja')
  comprobar('un cliente no entra a Caja (lo manda a su panel)', await ruta(), '/cliente')
  sim.sesion = ADMIN
  await irA('/cliente/pagos')
  comprobar('un administrador entra a Mis pagos desde Mis encomiendas', await ruta(), '/cliente/pagos')
  sim.sesion = null
  await irA('/administrador/caja')
  comprobar('sin sesión: a Ingresar', (await ruta()).startsWith('/ingresar'), true)
} finally {
  await navegador.cerrar()
}
terminar()
