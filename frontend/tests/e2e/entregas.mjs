// Pruebas de la pantalla Llegadas y entregas (módulo 8, Administrador, diapositiva 22) en un navegador real. La API está
// interceptada (sin PHP ni base de datos) y algunas respuestas se retrasan a propósito para reproducir carreras asíncronas.
//
// Uso:  1) en frontend/:  npm run dev      2) desde C:\Jatsa:  node frontend/tests/e2e/entregas.mjs
import { crearInforme, esperar, iniciarNavegador } from './arnes.mjs'

const RETRASO_MS = 1500
const exito = (datos, estado = 200, demoraMs = 0) => ({ estado, cuerpo: { data: datos }, demoraMs })
const error = (estado, codigo, mensaje, campos) => ({ estado, cuerpo: { error: { codigo, mensaje, ...(campos ? { campos } : {}) } } })

const MOQ = { codigo: 'MOQ001', nombre: 'Agencia Moquegua' }
const ILO = { codigo: 'ILO001', nombre: 'Agencia Ilo' }
const ADMIN = { id: 2, nombreUsuario: 'lflores', nombreCompleto: 'Luis Flores', rol: 'ADMINISTRADOR', sucursal: { id: 3, ...MOQ } }

const sim = { sesion: ADMIN, envios: {}, enCamino: [], fallaPendientes: false, lento: null, entrega: null, devolucion: null }

const envio = (codigo, nombre, tipoDocumento, numeroDocumento, importe, pagado, extra = {}) => ({
  codigoSeguimiento: codigo, estado: 'EN_DESTINO', tipoEntrega: 'AGENCIA', direccionEntrega: null, fechaHoraEntrega: null, origen: ILO, destino: MOQ,
  destinatario: { tipoDocumento, numeroDocumento, nombreCompleto: nombre },
  bultos: [{ numero: 1, descripcion: 'Sobre con documentos', pesoKg: '0.60' }], cantidadBultos: 1, pesoTotalKg: '0.60',
  importe, pagado, saldo: (Number(importe) - Number(pagado)).toFixed(2), ...extra,
})

function reiniciar() {
  sim.envios = {
    ENM2P6T8R1WQ: envio('ENM2P6T8R1WQ', 'María Elena Rojas Ccori', 'DNI', '71889345', '8.50', '8.50'),
    ENR5T6Y7U8I9: envio('ENR5T6Y7U8I9', 'Comercial Sur S.A.C.', 'RUC', '20123456789', '14.60', '0.00'),
    ENDOMICILIO1: envio('ENDOMICILIO1', 'Carlos Pérez Díaz', 'CE', 'AB123456', '9.15', '9.15', {
      tipoEntrega: 'DOMICILIO', direccionEntrega: 'Av. Siempre Viva 742',
      bultos: [{ numero: 1, descripcion: 'Caja', pesoKg: '2.00' }, { numero: 2, descripcion: 'Sobre', pesoKg: '0.40' }], cantidadBultos: 2, pesoTotalKg: '2.40',
    }),
  }
  sim.enCamino = [{ codigo: 'VJ2026103104', origen: ILO, destino: MOQ, fechaHoraSalida: '2026-10-31 07:15:00', llegadaEstimada: '2026-10-31 09:15:00', cantidadEnvios: 2 }]
}
reiniciar()

const listos = () => Object.values(sim.envios).filter((e) => e.estado === 'EN_DESTINO')
const normalizar = (t, n) => (t === 'CE' || t === 'PAS' ? n.replace(/[\s-]+/g, '').toUpperCase() : n.replace(/[\s-]+/g, ''))

const { comprobar, titulo, terminar } = crearInforme()
const navegador = await iniciarNavegador(9347, ({ metodo, ruta, cuerpo }) => {
  if (ruta === '/api/auth/yo') return sim.sesion ? exito({ usuario: sim.sesion }) : error(401, 'no_autenticado', 'Debes iniciar sesión.')

  if (ruta === '/api/entregas/pendientes') {
    if (sim.fallaPendientes) return error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')
    return exito({
      sucursal: MOQ, enCamino: sim.enCamino, hayMas: false,
      listos: listos().map((e) => ({ codigoSeguimiento: e.codigoSeguimiento, destinatario: e.destinatario.nombreCompleto, tipoEntrega: e.tipoEntrega, bultos: e.cantidadBultos, pesoKg: e.pesoTotalKg, importe: e.importe, pagado: e.pagado, saldo: e.saldo })),
    })
  }
  const uno = ruta.match(/^\/api\/entregas\/envios\/(EN[A-Z0-9]{10})$/)
  if (uno) return sim.envios[uno[1]] ? exito({ envio: sim.envios[uno[1]] }, 200, sim.lento === uno[1] ? RETRASO_MS : 0) : error(404, 'envio_no_encontrado', 'No se encontró el envío en esta agencia.')

  const entrega = ruta.match(/^\/api\/entregas\/envios\/(EN[A-Z0-9]{10})\/entrega$/)
  if (entrega && metodo === 'POST') {
    if (sim.entrega) return sim.entrega(cuerpo, sim.envios[entrega[1]])
    const e = sim.envios[entrega[1]]
    if (normalizar(cuerpo.tipoDocumento, cuerpo.numeroDocumento) !== e.destinatario.numeroDocumento || cuerpo.tipoDocumento !== e.destinatario.tipoDocumento) {
      return error(422, 'datos_invalidos', 'El documento presentado no coincide con el del destinatario registrado.', { numeroDocumento: 'El documento no coincide con el del destinatario registrado.' })
    }
    if (e.saldo !== '0.00') return error(409, 'saldo_pendiente', `El envío tiene un saldo pendiente de S/ ${e.saldo}: cóbralo en Caja antes de entregarlo.`)
    Object.assign(e, { estado: 'ENTREGADO', fechaHoraEntrega: '2026-10-31 09:30:00' })
    return exito({ envio: e })
  }
  const devolucion = ruta.match(/^\/api\/entregas\/envios\/(EN[A-Z0-9]{10})\/devolucion$/)
  if (devolucion && metodo === 'POST') {
    if (sim.devolucion) return sim.devolucion(sim.envios[devolucion[1]])
    sim.envios[devolucion[1]].estado = 'DEVUELTO'
    return exito({ envio: sim.envios[devolucion[1]] })
  }
  return null
})
const { irA, escribir, pulsar, texto, valor, existe, ruta, evaluar, llamadas } = navegador
const captura = async (nombre, ancho = 1280, alto = 800) => { if (process.env.CAPTURAS) await navegador.captura(`${process.env.CAPTURAS}/${nombre}.png`, ancho, alto) }
const llamadasA = (metodo, rutaApi) => llamadas.filter((l) => l.metodo === metodo && l.ruta === rutaApi)
const enlaces = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(a => a.innerText.trim() + '→' + a.getAttribute('href')).join(' | ')`)
const filas = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(r => r.innerText.replace(/\\s+/g,' ').trim()).join(' || ')`)
const deshabilitado = (selector) => evaluar(`document.querySelector(${JSON.stringify(selector)}).disabled`)
const LISTOS = '.tabla-listos tbody tr'
const CONFIRMAR = '.formulario-entrega button[type=submit]'
const botonPorTexto = (t) => `[...document.querySelectorAll('.formulario-entrega .acciones-formulario button, .formulario-entrega .acciones-formulario a')].find(b => b.innerText.trim() === ${JSON.stringify(t)})`
const estadoBoton = (t) => evaluar(`(() => { const b = ${botonPorTexto(t)}; return b ? (b.disabled === true) : null })()`)
const pulsarTexto = (t) => evaluar(`${botonPorTexto(t)}.click()`)

try {
  titulo('Llegadas y entregas: pantalla de la diapositiva 22')
  await irA('/administrador/llegadas-entregas')
  comprobar('título con el nombre de la agencia', await texto('h1'), 'Llegadas y entregas · Agencia Moquegua')
  comprobar('«en camino hacia aquí»: viaje, ruta y llegada aproximada', await filas('.viajes-en-camino li'), 'VJ2026103104 Agencia Ilo → Agencia Moquegua · llega aprox. 09:15 · 2 envíos')
  comprobar('«listos para entregar»: envío, destinatario y saldo', await filas(LISTOS),
    'ENM2P6T8R1WQ María Elena Rojas Ccori S/ 0.00 Atender || ENR5T6Y7U8I9 Comercial Sur S.A.C. S/ 14.60 Atender || ENDOMICILIO1 Carlos Pérez Díaz · a domicilio S/ 0.00 Atender')
  comprobar('el menú marca Llegadas y entregas y no hay panel hasta atender un envío', [(await enlaces('nav.menu a')).includes('Llegadas y entregas→/administrador/llegadas-entregas'), await existe('.entrega')], [true, false])
  comprobar('la lista no muestra documentos', (await texto('.tabla-listos')).includes('71889345'), false)
  await captura('22_llegadas_entregas')

  titulo('Atender y entregar')
  await pulsar(`${LISTOS}:first-child .celda-accion button`); await esperar(600)
  comprobar('panel «Entregar», estado y destinatario registrado con su documento (para el personal)', [await texto('.entrega-cabecera h2'), await texto('.entrega-cabecera .insignia'), await texto('.entrega > p')],
    ['Entregar ENM2P6T8R1WQ', 'EN DESTINO', 'Destinatario registrado: María Elena Rojas Ccori · DNI 71889345'])
  comprobar('resumen: bulto, peso, pagado y saldo', await texto('[aria-label="Resumen del envío"]'), '1 bulto · Sobre con documentos · 0.60 kg · Pagado S/ 8.50 · Saldo S/ 0.00')
  comprobar('sin documento escrito: «Confirmar entrega» deshabilitado y sin indicador', [await deshabilitado(CONFIRMAR), await texto('.coincidencia')], [true, ''])
  await escribir('#numeroDocumento', '71889346'); await esperar(150)
  comprobar('documento distinto: «✗ No coincide» y confirmar deshabilitado', [await texto('.coincidencia'), await deshabilitado(CONFIRMAR)], ['✗ No coincide', true])
  await escribir('#tipoDocumento', 'RUC'); await escribir('#numeroDocumento', '71889345'); await esperar(150)
  comprobar('tipo de documento distinto: no coincide (aunque el número sea igual); formato inválido: sin mensaje', [await texto('.coincidencia'), await deshabilitado(CONFIRMAR)], ['', true])
  await escribir('#tipoDocumento', 'DNI'); await escribir('#numeroDocumento', ' 7188-9345 '); await esperar(150)
  comprobar('documento correcto (acepta espacios y guiones): «✓ Coincide» y confirmar habilitado', [await texto('.coincidencia'), await deshabilitado(CONFIRMAR)], ['✓ Coincide', false])
  comprobar('sin saldo: «Cobrar saldo» deshabilitado; «Cambiar destino (solo REGISTRADO)» deshabilitado con su nota', [await estadoBoton('Cobrar saldo'), await estadoBoton('Cambiar destino (solo REGISTRADO)')], [true, true])
  comprobar('nota de la diapositiva', await texto('.formulario-entrega > p.ayuda'), 'Al confirmar se guarda la fecha y hora de entrega.')
  await captura('22_entregar')
  await pulsar(CONFIRMAR); await esperar(900)
  comprobar('se envía el documento presentado', llamadasA('POST', '/api/entregas/envios/ENM2P6T8R1WQ/entrega').at(-1)?.cuerpo, { tipoDocumento: 'DNI', numeroDocumento: ' 7188-9345 ' })
  comprobar('aviso de entrega con la fecha y hora', await texto('.resultado.ok'), 'Envío ENM2P6T8R1WQ entregado a María Elena Rojas Ccori el 31/10 09:30.')
  comprobar('el panel muestra «Entregado» con la hora y ya no hay formulario', [await texto('.entrega-cabecera h2'), await texto('.entrega .mensaje-aviso'), await existe('.formulario-entrega')], ['Entregado ENM2P6T8R1WQ', 'Entregado el 31/10 09:30.', false])
  comprobar('el envío sale de «listos para entregar»', (await filas(LISTOS)).includes('ENM2P6T8R1WQ'), false)

  titulo('Saldo pendiente: cobrar antes de entregar')
  await pulsar(`${LISTOS}:first-child .celda-accion button`); await esperar(600)
  comprobar('abre el envío de Comercial Sur (RUC)', [await texto('.entrega-cabecera h2'), await texto('.entrega > p')], ['Entregar ENR5T6Y7U8I9', 'Destinatario registrado: Comercial Sur S.A.C. · RUC 20123456789'])
  await escribir('#tipoDocumento', 'RUC'); await escribir('#numeroDocumento', '20123456789'); await esperar(150)
  comprobar('con saldo: aviso y confirmar deshabilitado aunque el documento coincida', [await texto('.coincidencia'), await texto('.formulario-entrega .mensaje-aviso'), await deshabilitado(CONFIRMAR)],
    ['✓ Coincide', 'El envío tiene un saldo de S/ 14.60: cóbralo en Caja antes de entregarlo.', true])
  comprobar('«Cobrar saldo» lleva a Caja con el envío', await enlaces('.formulario-entrega .acciones-formulario a'), 'Cobrar saldo→/administrador/caja?envio=ENR5T6Y7U8I9')

  titulo('Errores del servidor al confirmar')
  sim.envios.ENR5T6Y7U8I9.pagado = '14.60'; sim.envios.ENR5T6Y7U8I9.saldo = '0.00' // se cobró en otra caja
  sim.entrega = () => error(422, 'datos_invalidos', 'El documento presentado no coincide con el del destinatario registrado.', { numeroDocumento: 'El documento no coincide con el del destinatario registrado.' })
  await atenderOtraVez('ENR5T6Y7U8I9')
  await escribir('#tipoDocumento', 'RUC'); await escribir('#numeroDocumento', '20123456789'); await esperar(150)
  await pulsar(CONFIRMAR); await esperar(700)
  comprobar('422 del servidor: el error aparece en el campo y se queda en el formulario', [await texto('#numeroDocumento-error'), await existe('.formulario-entrega'), await evaluar('document.activeElement.id')], ['El documento no coincide con el del destinatario registrado.', true, 'numeroDocumento'])
  sim.entrega = () => { sim.envios.ENR5T6Y7U8I9.estado = 'ENTREGADO'; sim.envios.ENR5T6Y7U8I9.fechaHoraEntrega = '2026-10-31 09:29:00'; return error(409, 'envio_entregado', 'El envío ya fue entregado.') }
  await pulsar(CONFIRMAR); await esperar(900)
  comprobar('409 (otra caja lo entregó antes): aviso, se vuelve a leer el envío y las listas', [await texto('.resultado.error'), await texto('.entrega-cabecera h2'), (await filas(LISTOS)).includes('ENR5T6Y7U8I9')], ['El envío ya fue entregado.', 'Entregado ENR5T6Y7U8I9', false])
  sim.entrega = null

  titulo('Marcar devuelto (con confirmación)')
  await pulsar(`${LISTOS}:first-child .celda-accion button`); await esperar(600)
  await pulsarTexto('Marcar devuelto'); await esperar(150)
  comprobar('pide confirmación y no llama a la API', [await texto('.formulario-entrega .confirmar-cancelacion'), llamadasA('POST', '/api/entregas/envios/ENDOMICILIO1/devolucion').length], ['¿Marcar devuelto? Sí, marcar No', 0])
  await pulsar('.formulario-entrega .confirmar-cancelacion .boton-claro'); await esperar(150)
  comprobar('«No» cancela', [await existe('.confirmar-cancelacion'), llamadasA('POST', '/api/entregas/envios/ENDOMICILIO1/devolucion').length], [false, 0])
  comprobar('a domicilio: muestra la dirección y el resumen con varios bultos', [await texto('.entrega .ayuda'), await texto('[aria-label="Resumen del envío"]')], ['Entrega a domicilio: Av. Siempre Viva 742', '2 bultos · 2.40 kg · Pagado S/ 9.15 · Saldo S/ 0.00'])
  await pulsarTexto('Marcar devuelto'); await pulsar('.formulario-entrega .confirmar-cancelacion .boton-marino'); await esperar(900)
  comprobar('devuelto: aviso, el panel lo indica y sale de «listos»', [await texto('.resultado.ok'), await texto('.entrega-cabecera h2'), await texto('.entrega .mensaje-aviso'), (await filas(LISTOS)).includes('ENDOMICILIO1')], ['Envío ENDOMICILIO1 marcado como devuelto.', 'Devuelto ENDOMICILIO1', 'Este envío fue marcado como devuelto.', false])

  titulo('Dos atenciones seguidas (carrera) y estados vacío y de error')
  reiniciar()
  sim.lento = 'ENM2P6T8R1WQ'
  await irA('/administrador/llegadas-entregas')
  await pulsar(`${LISTOS}:nth-child(1) .celda-accion button`); await esperar(300)
  await pulsar(`${LISTOS}:nth-child(2) .celda-accion button`); await esperar(500)
  comprobar('se muestra el último envío elegido', await texto('.entrega-cabecera h2'), 'Entregar ENR5T6Y7U8I9')
  await esperar(RETRASO_MS + 300)
  comprobar('la respuesta tardía del envío anterior no la pisa', await texto('.entrega-cabecera h2'), 'Entregar ENR5T6Y7U8I9')
  sim.lento = null
  sim.envios = {}; sim.enCamino = []
  await irA('/administrador/llegadas-entregas')
  comprobar('sin viajes ni envíos: dos estados vacíos', await evaluar(`[...document.querySelectorAll('.estado-vacio')].map(e => e.innerText.trim()).join(' | ')`), 'No hay viajes en camino hacia esta agencia. | No hay envíos listos para entregar en esta agencia.')
  sim.fallaPendientes = true
  await irA('/administrador/llegadas-entregas')
  comprobar('si falla: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudieron cargar las llegadas y entregas. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallaPendientes = false; reiniciar()
  await pulsar('.resultado.error button'); await esperar(700)
  comprobar('«Reintentar» recarga', (await filas(LISTOS)).startsWith('ENM2P6T8R1WQ'), true)

  if (process.env.CAPTURAS) {
    // Revisión en móvil: nada debe obligar a desplazarse de lado.
    titulo('Móvil (solo con CAPTURAS)')
    await irA('/administrador/llegadas-entregas')
    await pulsar(`${LISTOS}:nth-child(1) .celda-accion button`); await esperar(600)
    await escribir('#numeroDocumento', '71889345'); await esperar(150)
    await captura('m_llegadas_entregas', 390, 844)
    comprobar('m_llegadas_entregas: sin desplazamiento horizontal', await evaluar('document.documentElement.scrollWidth <= window.innerWidth'), true)
  }

  titulo('Accesos por rol')
  sim.sesion = { ...ADMIN, rol: 'CLIENTE', sucursal: null }
  await irA('/administrador/llegadas-entregas')
  comprobar('un cliente no entra (lo manda a su panel)', await ruta(), '/cliente')
  sim.sesion = { ...ADMIN, rol: 'TRANSPORTISTA' }
  await irA('/administrador/llegadas-entregas')
  comprobar('un transportista no entra (lo manda a Mis viajes)', await ruta(), '/transportista')
  sim.sesion = null
  await irA('/administrador/llegadas-entregas')
  comprobar('sin sesión: a Ingresar', (await ruta()).startsWith('/ingresar'), true)
} finally {
  await navegador.cerrar()
}
terminar()

// Reabre un envío en el panel recargando la pantalla (útil después de cambiar lo que responde el simulador).
async function atenderOtraVez(codigo) {
  await irA('/administrador/llegadas-entregas')
  await evaluar(`[...document.querySelectorAll('.tabla-listos tbody tr')].find(r => r.innerText.includes(${JSON.stringify(codigo)})).querySelector('button').click()`)
  await esperar(600)
}
