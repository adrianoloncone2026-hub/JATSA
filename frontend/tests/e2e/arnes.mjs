// Arnés común de las pruebas de navegador (sin dependencias): abre Edge sin ventana con el protocolo de
// depuración e INTERCEPTA todas las llamadas a /api. Cada prueba decide qué responde la API con `atender`,
// así no hace falta PHP ni base de datos y se pueden provocar fallos y retrasos a propósito.
import { spawn } from 'node:child_process'
import { existsSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const BASE = process.env.FRONTEND_URL ?? 'http://localhost:5173'
export const esperar = (ms) => new Promise((resolver) => setTimeout(resolver, ms))

function buscarEdge() {
  const ruta = [
    process.env.EDGE_PATH,
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
  ].find((candidata) => candidata && existsSync(candidata))
  if (!ruta) {
    console.error('No se encontró msedge.exe. Define EDGE_PATH.')
    process.exit(2)
  }
  return ruta
}

/**
 * @param {number} puerto Puerto de depuración (distinto por prueba).
 * @param {(solicitud: {metodo: string, ruta: string, url: URL, cuerpo: any}) => ({estado: number, cuerpo: any, demoraMs?: number} | null)} atender
 *        Devuelve la respuesta falsa de la API, o null para dejar pasar la petición.
 */
export async function iniciarNavegador(puerto, atender) {
  const perfil = mkdtempSync(join(tmpdir(), 'jatsa-e2e-'))
  const edge = spawn(buscarEdge(), ['--headless=new', '--disable-gpu', '--no-first-run', `--user-data-dir=${perfil}`,
    `--remote-debugging-port=${puerto}`, 'about:blank'], { stdio: 'ignore' })

  let urlSocket = null
  for (let i = 0; i < 40 && !urlSocket; i++) {
    try {
      const paginas = await (await fetch(`http://127.0.0.1:${puerto}/json/list`)).json()
      urlSocket = paginas.find((p) => p.type === 'page')?.webSocketDebuggerUrl ?? null
    } catch { /* Edge está arrancando */ }
    if (!urlSocket) await esperar(250)
  }
  if (!urlSocket) throw new Error('Edge no abrió el puerto de depuración')

  const ws = new WebSocket(urlSocket)
  await new Promise((resolver) => (ws.onopen = resolver))
  let ultimoId = 0
  const pendientes = new Map()
  const llamadas = [] // lo que la página pidió a la API: { metodo, ruta, cuerpo }

  const enviar = (method, params = {}) => new Promise((resolver) => {
    const id = ++ultimoId
    pendientes.set(id, resolver)
    ws.send(JSON.stringify({ id, method, params }))
  })
  const sinRespuesta = (method, params) => ws.send(JSON.stringify({ id: ++ultimoId, method, params }))

  function responder(requestId, { estado, cuerpo }) {
    sinRespuesta('Fetch.fulfillRequest', {
      requestId, responseCode: estado,
      responseHeaders: [{ name: 'Content-Type', value: 'application/json' }],
      body: Buffer.from(JSON.stringify(cuerpo)).toString('base64'),
    })
  }

  ws.onmessage = (mensaje) => {
    const datos = JSON.parse(mensaje.data)
    if (datos.id && pendientes.has(datos.id)) pendientes.get(datos.id)(datos)
    if (datos.method !== 'Fetch.requestPaused') return

    const { requestId, request } = datos.params
    const url = new URL(request.url)
    let cuerpo = null
    if (request.postData) {
      try { cuerpo = JSON.parse(request.postData) } catch { cuerpo = request.postData }
    }
    const solicitud = { metodo: request.method, ruta: url.pathname, url, cuerpo }
    llamadas.push({ metodo: solicitud.metodo, ruta: solicitud.ruta, cuerpo })

    const respuesta = atender(solicitud)
    if (!respuesta) return sinRespuesta('Fetch.continueRequest', { requestId })
    if (respuesta.demoraMs) setTimeout(() => responder(requestId, respuesta), respuesta.demoraMs)
    else responder(requestId, respuesta)
  }

  await enviar('Page.enable')
  await enviar('Runtime.enable')
  await enviar('Fetch.enable', { patterns: [{ urlPattern: '*/api/*' }] })

  const evaluar = async (expresion) => (await enviar('Runtime.evaluate', { expression: expresion, returnByValue: true, awaitPromise: true })).result?.result?.value
  const citar = JSON.stringify

  const navegador = {
    llamadas,
    evaluar,
    esperar,
    irA: async (ruta, ms = 1800) => { await enviar('Page.navigate', { url: BASE + ruta }); await esperar(ms) },
    escribir: (selector, valor) => evaluar(`(() => { const el = document.querySelector(${citar(selector)});
      const proto = el.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
      Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${citar(valor)});
      el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); return true })()`),
    pulsar: (selector) => evaluar(`document.querySelector(${citar(selector)}).click()`),
    texto: (selector) => evaluar(`document.querySelector(${citar(selector)})?.innerText?.replace(/\\s+/g, ' ').trim() ?? null`),
    valor: (selector) => evaluar(`document.querySelector(${citar(selector)})?.value ?? null`),
    existe: (selector) => evaluar(`document.querySelector(${citar(selector)}) !== null`),
    enfocado: () => evaluar(`document.activeElement?.name || document.activeElement?.id || null`),
    ruta: () => evaluar('location.pathname + location.search'),
    // Guarda una captura de pantalla (PNG) con el tamaño de ventana indicado; sirve para revisar el diseño.
    captura: async (archivo, ancho = 1280, alto = 720) => {
      await enviar('Emulation.setDeviceMetricsOverride', { width: ancho, height: alto, deviceScaleFactor: 1, mobile: ancho < 600 })
      await esperar(300)
      const { writeFileSync } = await import('node:fs')
      writeFileSync(archivo, Buffer.from((await enviar('Page.captureScreenshot', { format: 'png' })).result.data, 'base64'))
    },
    cerrar: async () => {
      ws.close()
      edge.kill()
      await esperar(500)
      try { rmSync(perfil, { recursive: true, force: true }) } catch { /* Edge aún libera archivos */ }
    },
  }
  return navegador
}

// ── Mini marco de pruebas ───────────────────────────────────────────────────
export function crearInforme() {
  const fallos = []
  let correctas = 0
  return {
    titulo: (texto) => console.log(`\n${texto}`),
    comprobar(nombre, obtenido, esperado) {
      const igual = JSON.stringify(obtenido) === JSON.stringify(esperado)
      if (igual) correctas++
      else fallos.push(`${nombre}\n     esperado: ${JSON.stringify(esperado)}\n     obtenido: ${JSON.stringify(obtenido)}`)
      console.log(`${igual ? '  ✓' : '  ✗'} ${nombre}${igual ? '' : `  (obtenido ${JSON.stringify(obtenido)})`}`)
    },
    terminar() {
      console.log(`\n${correctas} correctas, ${fallos.length} con fallo`)
      for (const fallo of fallos) console.log(`  ✗ ${fallo}`)
      process.exit(fallos.length === 0 ? 0 : 1)
    },
  }
}
