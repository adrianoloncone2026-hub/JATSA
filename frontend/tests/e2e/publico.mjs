// Pruebas de regresión de las pantallas públicas (módulo 2) en un navegador real.
//
// Cómo funciona: abre Edge sin ventana (protocolo de depuración, sin dependencias) contra el servidor
// de desarrollo de Vite e INTERCEPTA todas las llamadas a /api con respuestas fijas. No necesita PHP
// ni base de datos, y permite retrasar a propósito una respuesta para reproducir carreras asíncronas.
//
// Uso:  1) en frontend/:  npm run dev      2) en otra terminal:  node frontend/tests/e2e/publico.mjs
// Opcionales: FRONTEND_URL (por defecto http://localhost:5173), EDGE_PATH (ruta de msedge.exe).
import { spawn } from 'node:child_process'
import { existsSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const BASE = process.env.FRONTEND_URL ?? 'http://localhost:5173'
const PUERTO = 9340
const RETRASO_MS = 1500 // lo que tarda la respuesta «lenta» del cotizador

const EDGE = [
  process.env.EDGE_PATH,
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
].find((ruta) => ruta && existsSync(ruta))
if (!EDGE) {
  console.error('No se encontró msedge.exe. Define EDGE_PATH.')
  process.exit(2)
}

// ── Respuestas fijas de la API ─────────────────────────────────────────────
const AGENCIAS = [
  ['AQP001', 'Agencia Arequipa Centro', 'Arequipa', 'Arequipa', 'Cercado', 'Av. Ejército 512', ['054201450', '054201451']],
  ['ILO001', 'Agencia Ilo', 'Moquegua', 'Ilo', 'Ilo', 'Av. Costanera 120', ['053481560']],
  ['LIM001', 'Agencia Lima Centro', 'Lima', 'Lima', 'Cercado de Lima', 'Av. Grau 450', ['014251230']],
  ['MOQ001', 'Agencia Moquegua', 'Moquegua', 'Mariscal Nieto', 'Moquegua', 'Jr. Ancash 325', ['053461120']],
].map(([codigo, nombre, departamento, provincia, distrito, direccion, telefonos]) => ({
  codigo, nombre, departamento, provincia, distrito, direccion, telefonos,
}))

function tarifa(tipo, maximo, precio, horas) {
  return { tipo, pesoMaximoKg: maximo, disponible: precio !== null, precioPorBulto: precio, tiempoHoras: precio ? horas : null,
    precioBase: precio ? '10.00' : null, precioPorKm: precio ? '0.020' : null, distanciaKm: precio ? '230' : null }
}
function cotizacionPara(peso) {
  const esSobre = Number(peso) <= 1
  const tarifas = [tarifa('SOBRE', 1, '9.15', 4), tarifa('PAQUETE', 30, '14.60', 4), tarifa('CARGA', 300, null, 0)]
  return {
    origen: { codigo: 'MOQ001', nombre: 'Agencia Moquegua' }, destino: { codigo: 'AQP001', nombre: 'Agencia Arequipa Centro' },
    pesoKg: Number(peso).toFixed(2), tipo: esSobre ? 'SOBRE' : 'PAQUETE', cotizacion: esSobre ? tarifas[0] : tarifas[1], tarifas,
  }
}

// ── Arranque del navegador ─────────────────────────────────────────────────
const perfil = mkdtempSync(join(tmpdir(), 'jatsa-e2e-'))
const edge = spawn(EDGE, ['--headless=new', '--disable-gpu', '--no-first-run', `--user-data-dir=${perfil}`,
  `--remote-debugging-port=${PUERTO}`, 'about:blank'], { stdio: 'ignore' })
const esperar = (ms) => new Promise((r) => setTimeout(r, ms))

async function conectar() {
  for (let i = 0; i < 40; i++) {
    try {
      const lista = await (await fetch(`http://127.0.0.1:${PUERTO}/json/list`)).json()
      const pagina = lista.find((t) => t.type === 'page')
      if (pagina) return pagina.webSocketDebuggerUrl
    } catch { /* Edge está arrancando */ }
    await esperar(250)
  }
  throw new Error('Edge no abrió el puerto de depuración')
}

const ws = new WebSocket(await conectar())
await new Promise((r) => (ws.onopen = r))
let ultimoId = 0
const pendientes = new Map()
const llamadas = { agencias: 0, cotizacion: [] } // lo que la página pidió a la API

function responder(requestId, estado, cuerpo) {
  const id = ++ultimoId
  ws.send(JSON.stringify({ id, method: 'Fetch.fulfillRequest', params: {
    requestId, responseCode: estado, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }],
    body: Buffer.from(JSON.stringify(cuerpo)).toString('base64') } }))
}

ws.onmessage = (mensaje) => {
  const x = JSON.parse(mensaje.data)
  if (x.id && pendientes.has(x.id)) pendientes.get(x.id)(x)
  if (x.method !== 'Fetch.requestPaused') return

  const { requestId, request } = x.params
  const url = new URL(request.url)
  if (url.pathname === '/api/auth/yo') return responder(requestId, 401, { error: { codigo: 'no_autenticado', mensaje: 'Debes iniciar sesión.' } })
  if (url.pathname === '/api/agencias') {
    llamadas.agencias++
    return responder(requestId, 200, { data: { agencias: AGENCIAS } })
  }
  if (url.pathname === '/api/cotizacion') {
    const peso = url.searchParams.get('peso')
    llamadas.cotizacion.push(peso)
    // La respuesta de 8.80 kg se retrasa; la de cualquier otro peso sale enseguida.
    const demora = peso === '8.80' ? RETRASO_MS : 0
    return void setTimeout(() => responder(requestId, 200, { data: cotizacionPara(peso) }), demora)
  }
  const id = ++ultimoId
  ws.send(JSON.stringify({ id, method: 'Fetch.continueRequest', params: { requestId } }))
}

const enviar = (method, params = {}) => new Promise((r) => { const id = ++ultimoId; pendientes.set(id, r); ws.send(JSON.stringify({ id, method, params })) })
const evaluar = async (expresion) => (await enviar('Runtime.evaluate', { expression: expresion, returnByValue: true, awaitPromise: true })).result?.result?.value
const irA = async (ruta) => { await enviar('Page.navigate', { url: BASE + ruta }); await esperar(1800) }
const escribir = (selector, valor) => evaluar(`(() => { const el = document.querySelector(${JSON.stringify(selector)});
  const proto = el.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(valor)});
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); return true })()`)
const pulsar = (selector) => evaluar(`document.querySelector(${JSON.stringify(selector)}).click()`)
const texto = (selector) => evaluar(`document.querySelector(${JSON.stringify(selector)})?.innerText?.replace(/\\s+/g, ' ').trim() ?? null`)
const ruta = () => evaluar('location.pathname + location.search')
const CALCULAR = '.formulario-cotizador button[type=submit]'

// ── Mini marco de pruebas ──────────────────────────────────────────────────
const fallos = []
let correctas = 0
function comprobar(nombre, obtenido, esperado) {
  const igual = JSON.stringify(obtenido) === JSON.stringify(esperado)
  if (igual) correctas++
  else fallos.push(`${nombre}\n     esperado: ${JSON.stringify(esperado)}\n     obtenido: ${JSON.stringify(obtenido)}`)
  console.log(`${igual ? '  ✓' : '  ✗'} ${nombre}${igual ? '' : `  (obtenido ${JSON.stringify(obtenido)})`}`)
}

async function prepararCotizador() {
  await irA('/cotizador')
  await escribir('#origen', 'MOQ001')
  await escribir('#destino', 'AQP001')
}

try {
  await enviar('Page.enable'); await enviar('Runtime.enable')
  await enviar('Fetch.enable', { patterns: [{ urlPattern: '*/api/*' }] })

  console.log('\nCotizador: respuestas obsoletas (carrera asíncrona)')

  // Control: sin tocar nada, la respuesta lenta sí se muestra.
  await prepararCotizador()
  await escribir('#peso', '8.80'); await pulsar(CALCULAR)
  await esperar(RETRASO_MS + 900)
  comprobar('control: sin cambios, la respuesta lenta (8.80 kg) se muestra', await texto('.precio'), 'S/ 14.60 por bulto')

  // Caso A: se edita un campo mientras la cotización está pendiente → no debe aparecer ningún resultado.
  await prepararCotizador()
  await escribir('#peso', '8.80'); await pulsar(CALCULAR)
  await esperar(300)
  await escribir('#peso', '0.40') // cambia el dato con la petición de 8.80 todavía en vuelo
  await esperar(RETRASO_MS + 900) // …y deja que llegue la respuesta vieja
  comprobar('A: editar el peso con la cotización pendiente no deja ver el precio anterior', await texto('.resultado-cotizacion'), null)
  comprobar('A: el botón vuelve a estar disponible (no queda en «Calculando…»)', await texto(CALCULAR), 'Calcular')

  // Caso B: una cotización nueva (rápida) se adelanta a la vieja (lenta) → debe quedar la nueva.
  await prepararCotizador()
  await escribir('#peso', '8.80'); await pulsar(CALCULAR)
  await esperar(300)
  await escribir('#peso', '0.40'); await pulsar(CALCULAR)
  await esperar(700)
  comprobar('B: la cotización nueva (0.40 kg) se muestra al llegar', await texto('.precio'), 'S/ 9.15 por bulto')
  await esperar(RETRASO_MS + 600) // llega tarde la respuesta de 8.80 kg
  comprobar('B: la respuesta tardía de 8.80 kg no sobrescribe a la nueva', await texto('.precio'), 'S/ 9.15 por bulto')
  comprobar('B: el tipo mostrado sigue siendo el de 0.40 kg', await texto('.resultado-cabecera h2'), 'Tipo SOBRE (hasta 1 kg)')

  // Caso C: cambiar origen/destino con la cotización pendiente también la descarta.
  await prepararCotizador()
  await escribir('#peso', '8.80'); await pulsar(CALCULAR)
  await esperar(300)
  await escribir('#destino', 'LIM001')
  await esperar(RETRASO_MS + 900)
  comprobar('C: cambiar el destino con la cotización pendiente no deja ver el precio anterior', await texto('.resultado-cotizacion'), null)

  console.log('\nAgencias: la dirección y la ficha no se contradicen')
  await irA('/agencias')
  comprobar('sin ?agencia=, la dirección refleja la ficha mostrada (la primera)', await ruta(), '/agencias?agencia=AQP001')
  await escribir('#filtro-departamento', 'Moquegua')
  await esperar(500)
  comprobar('al filtrar por Moquegua la ficha pasa a la primera agencia visible', await texto('.ficha-agencia h2'), 'Agencia Ilo · ILO001')
  comprobar('…y la dirección se actualiza a la misma agencia', await ruta(), '/agencias?agencia=ILO001')
  await pulsar('.agencia-item:nth-child(2) button')
  await esperar(300)
  comprobar('elegir otra agencia con «Ver» actualiza ficha y dirección', [await texto('.ficha-agencia h2'), await ruta()], ['Agencia Moquegua · MOQ001', '/agencias?agencia=MOQ001'])
  await irA('/agencias?agencia=ZZZ999')
  comprobar('un código inexistente en la dirección se corrige a la agencia mostrada', await ruta(), '/agencias?agencia=AQP001')
  await irA('/agencias?agencia=MOQ001')
  await escribir('#filtro-departamento', 'Lima')
  await esperar(500)
  comprobar('si la agencia de la dirección queda fuera del filtro, ficha y dirección pasan a la visible', [await texto('.ficha-agencia h2'), await ruta()], ['Agencia Lima Centro · LIM001', '/agencias?agencia=LIM001'])

  console.log('\nInicio: lema fijo, independiente de la API')
  llamadas.agencias = 0
  await irA('/')
  comprobar('el lema es el texto editorial del prototipo', await texto('.hero-lugares'), 'Moquegua · Arequipa · Lima · Ilo')
  comprobar('el Inicio no consulta /api/agencias', llamadas.agencias, 0)
} finally {
  ws.close()
  edge.kill()
  await esperar(500)
  try { rmSync(perfil, { recursive: true, force: true }) } catch { /* Edge aún libera archivos */ }
}

console.log(`\n${correctas} correctas, ${fallos.length} con fallo`)
for (const f of fallos) console.log(`  ✗ ${f}`)
process.exit(fallos.length === 0 ? 0 : 1)
