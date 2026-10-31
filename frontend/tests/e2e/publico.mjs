// Pruebas de regresión de las pantallas públicas (módulo 2) en un navegador real.
// Usa el arnés común (arnes.mjs): Edge sin ventana con la API interceptada, sin PHP ni base de datos.
//
// Uso:  1) en frontend/:  npm run dev      2) desde C:\Jatsa:  node frontend/tests/e2e/publico.mjs
// Opcionales: FRONTEND_URL (por defecto http://localhost:5173), EDGE_PATH (ruta de msedge.exe).
import { crearInforme, esperar, iniciarNavegador } from './arnes.mjs'

const RETRASO_MS = 1500 // lo que tarda la respuesta «lenta» del cotizador

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

const { comprobar, titulo, terminar } = crearInforme()
let agenciasPedidas = 0

const navegador = await iniciarNavegador(9340, ({ ruta, url }) => {
  if (ruta === '/api/auth/yo') return { estado: 401, cuerpo: { error: { codigo: 'no_autenticado', mensaje: 'Debes iniciar sesión.' } } }
  if (ruta === '/api/agencias') {
    agenciasPedidas++
    return { estado: 200, cuerpo: { data: { agencias: AGENCIAS } } }
  }
  if (ruta === '/api/cotizacion') {
    const peso = url.searchParams.get('peso')
    // La respuesta de 8.80 kg se retrasa; la de cualquier otro peso sale enseguida.
    return { estado: 200, cuerpo: { data: cotizacionPara(peso) }, demoraMs: peso === '8.80' ? RETRASO_MS : 0 }
  }
  return null
})
const { irA, escribir, pulsar, texto, ruta } = navegador
const CALCULAR = '.formulario-cotizador button[type=submit]'

async function prepararCotizador() {
  await irA('/cotizador')
  await escribir('#origen', 'MOQ001')
  await escribir('#destino', 'AQP001')
}

try {
  titulo('Cotizador: respuestas obsoletas (carrera asíncrona)')

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

  titulo('Agencias: la dirección y la ficha no se contradicen')
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

  titulo('Inicio: lema fijo, independiente de la API')
  agenciasPedidas = 0
  await irA('/')
  comprobar('el lema es el texto editorial del prototipo', await texto('.hero-lugares'), 'Moquegua · Arequipa · Lima · Ilo')
  comprobar('el Inicio no consulta /api/agencias', agenciasPedidas, 0)
} finally {
  await navegador.cerrar()
}
terminar()
