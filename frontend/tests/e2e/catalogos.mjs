// Pruebas de las pantallas del módulo 10 en un navegador real: Catálogos (diapositiva 29: Sucursales, Rutas y precios, Vehículos)
// y Cuentas (diapositiva 30). La API está interceptada y es CON ESTADO: reproduce las reglas del backend (cierre de vigencia, 409 de
// uso, último gerente, etc.) para probar que la pantalla muestra lo que el servidor decide. No usa PHP ni base de datos.
//
// Uso:  1) en frontend/:  npm run dev      2) desde C:\Jatsa:  node frontend/tests/e2e/catalogos.mjs
import { crearInforme, esperar, iniciarNavegador } from './arnes.mjs'

const HOY = '2026-11-01'
const RETRASO_MS = 1500
const exito = (datos, estado = 200, demoraMs = 0) => ({ estado, cuerpo: { data: datos }, demoraMs })
const error = (estado, codigo, mensaje, campos) => ({ estado, cuerpo: { error: { codigo, mensaje, ...(campos ? { campos } : {}) } } })

const GERENTE = { id: 1, nombreUsuario: 'rquispe', nombreCompleto: 'Rosa María Quispe Mamani', rol: 'GERENTE', sucursal: null }
const ADMIN = { id: 2, nombreUsuario: 'lflores', nombreCompleto: 'Luis Alberto Flores Condori', rol: 'ADMINISTRADOR', sucursal: { id: 3, codigo: 'MOQ001', nombre: 'Agencia Moquegua' } }

// ── Estado del servidor simulado ────────────────────────────────────────────
const sim = {}
function reiniciar() {
  const s = (codigo, nombre, activa = true, telefonos = ['953123456']) => ({ codigo, nombre, departamento: 'Dep', provincia: 'Prov', distrito: 'Dist', direccion: 'Av. Principal 100', telefonos, activa })
  sim.sesion = GERENTE
  sim.sucursales = [s('AQP001', 'Agencia Arequipa Centro'), s('ILO001', 'Agencia Ilo'), s('LIM001', 'Agencia Lima Centro'), s('MOQ001', 'Agencia Moquegua'), s('TAC001', 'Agencia Tacna', false, [])]
  const r = (id, o, d, tipo, base, km, dist, h) => ({ id, origen: o, destino: d, tipoEnvio: tipo, precioBase: base, precioPorKm: km, distanciaKm: dist, tiempoHoras: h, vigenteDesde: '2026-09-01', vigenteHasta: null })
  sim.rutas = [r(1, 'MOQ001', 'AQP001', 'SOBRE', '8.00', '0.005', '230.0', 4), r(2, 'MOQ001', 'AQP001', 'PAQUETE', '10.00', '0.020', '230.0', 4), r(3, 'AQP001', 'LIM001', 'PAQUETE', '10.00', '0.020', '1010.0', 18),
    r(4, 'LIM001', 'ILO001', 'CARGA', '40.00', '0.080', '1200.0', 24), r(5, 'ILO001', 'MOQ001', 'SOBRE', '8.00', '0.005', '100.0', 2)]
  sim.siguienteRuta = 6
  sim.vehiculos = [{ placa: 'B7K-210', tipoVehiculo: 'FURGON', capacidadKg: '1500.00', activo: true, viajesAbiertos: 0 }, { placa: 'C3M-956', tipoVehiculo: 'CAMION', capacidadKg: '18000.00', activo: true, viajesAbiertos: 1 }]
  const c = (usuario, persona, rol, suc, activa = true) => ({ usuario, persona, rol, sucursal: suc ? { codigo: suc, nombre: `Agencia ${suc}` } : null, activa })
  sim.cuentas = [c('rquispe', 'Rosa María Quispe Mamani', 'GERENTE', null), c('lflores', 'Luis Alberto Flores Condori', 'ADMINISTRADOR', 'MOQ001'), c('mrojas', 'María Elena Rojas Ccori', 'CLIENTE', null),
    c('comsur', 'Comercial Sur S.A.C.', 'EMPRESARIO', null), c('pmamani', 'Pedro Mamani Huanca', 'TRANSPORTISTA', 'AQP001')]
  sim.fallas = { sucursales: false, rutas: false, vehiculos: false, cuentas: false }
  sim.vacio = false
  sim.cuentasLentas = null // texto de búsqueda cuya respuesta se retrasa
  sim.bloqueoReactivar = null // usuario cuya reactivación el servidor rechaza (su sucursal está desactivada)
}
reiniciar()

const nombreSucursal = (codigo) => sim.sucursales.find((x) => x.codigo === codigo)?.nombre ?? codigo
const presentarRuta = (x) => ({ ...x, origen: { codigo: x.origen, nombre: nombreSucursal(x.origen) }, destino: { codigo: x.destino, nombre: nombreSucursal(x.destino) }, vigente: x.vigenteHasta === null,
  costoBulto: (Number(x.precioBase) + Number(x.precioPorKm) * Number(x.distanciaKm)).toFixed(2) })
const esDecimal = (v, enteros, decimales) => new RegExp(`^\\d{1,${enteros}}([.,]\\d{1,${decimales}})?$`).test(String(v ?? '').trim())
function erroresPrecio(d) {
  const e = {}
  if (!esDecimal(d.precioBase, 6, 2) || Number(String(d.precioBase).replace(',', '.')) <= 0) e.precioBase = 'El precio base es mayor que 0 (hasta 2 decimales).'
  if (!esDecimal(d.precioPorKm, 3, 3)) e.precioPorKm = 'El precio por km es 0 o más (hasta 3 decimales).'
  if (!esDecimal(d.distanciaKm, 6, 1) || Number(String(d.distanciaKm).replace(',', '.')) <= 0) e.distanciaKm = 'Los kilómetros son más de 0 (hasta 1 decimal).'
  if (!Number.isInteger(d.tiempoHoras) || d.tiempoHoras < 1) e.tiempoHoras = 'Las horas son un entero de 1 en adelante.'
  return e
}
const invalido = (campos) => error(422, 'datos_invalidos', 'Revisa los campos marcados.', campos)
const norm = (v) => String(v).replace(',', '.')

const { comprobar, titulo, terminar } = crearInforme()
const navegador = await iniciarNavegador(9349, ({ metodo, ruta, url, cuerpo }) => {
  const q = (clave) => url.searchParams.get(clave) ?? ''
  if (ruta === '/api/auth/yo') return sim.sesion ? exito({ usuario: sim.sesion }) : error(401, 'no_autenticado', 'Debes iniciar sesión.')
  const falla = error(500, 'error_interno', 'Ocurrió un error interno. Inténtalo de nuevo más tarde.')

  // ── Sucursales ───────────────────────────────────────────────────────────
  if (ruta === '/api/catalogos/sucursales' && metodo === 'GET') return sim.fallas.sucursales ? falla : exito({ sucursales: sim.vacio ? [] : sim.sucursales })
  if (ruta === '/api/catalogos/sucursales' && metodo === 'POST') {
    const e = {}
    if (!/^[A-Za-z]{3}\d{3}$/.test(cuerpo.codigoSucursal ?? '')) e.codigoSucursal = 'El código son tres letras y tres dígitos. Por ejemplo: AQP001.'
    if (!String(cuerpo.nombre ?? '').trim()) e.nombre = 'Ingresa el nombre.'
    if (Object.keys(e).length) return invalido(e)
    if (sim.sucursales.some((x) => x.codigo === cuerpo.codigoSucursal.toUpperCase())) return error(409, 'sucursal_existente', 'Ya existe una sucursal con ese código.', { codigoSucursal: 'Ya existe una sucursal con ese código.' })
    const nueva = { codigo: cuerpo.codigoSucursal.toUpperCase(), nombre: cuerpo.nombre.trim(), departamento: cuerpo.departamento, provincia: cuerpo.provincia, distrito: cuerpo.distrito, direccion: cuerpo.direccion, telefonos: cuerpo.telefonos, activa: true }
    sim.sucursales.push(nueva)
    return exito({ sucursal: nueva }, 201)
  }
  const suc = ruta.match(/^\/api\/catalogos\/sucursales\/([A-Z0-9]+)(\/estado)?$/)
  if (suc) {
    const s = sim.sucursales.find((x) => x.codigo === suc[1])
    if (!s) return error(404, 'sucursal_no_encontrada', 'No se encontró la sucursal.')
    if (suc[2]) {
      if (!cuerpo.activa && s.codigo === 'MOQ001') return error(409, 'sucursal_con_personal', 'No se puede desactivar: tiene 2 cuenta(s) de personal activa(s). Desactívalas o muévelas a otra sucursal primero.')
      s.activa = cuerpo.activa
      return exito({ sucursal: s })
    }
    if (!String(cuerpo.nombre ?? '').trim()) return invalido({ nombre: 'Ingresa el nombre.' })
    Object.assign(s, { nombre: cuerpo.nombre.trim(), departamento: cuerpo.departamento, provincia: cuerpo.provincia, distrito: cuerpo.distrito, direccion: cuerpo.direccion, telefonos: cuerpo.telefonos })
    return exito({ sucursal: s })
  }

  // ── Rutas y precios ──────────────────────────────────────────────────────
  if (ruta === '/api/catalogos/rutas' && metodo === 'GET') return sim.fallas.rutas ? falla : exito({ rutas: sim.vacio ? [] : sim.rutas.map(presentarRuta) })
  if (ruta === '/api/catalogos/rutas' && metodo === 'POST') {
    const e = erroresPrecio(cuerpo)
    if (!cuerpo.origen) e.origen = 'Elige la agencia de origen.'
    if (!cuerpo.destino) e.destino = 'Elige la agencia de destino.'
    else if (cuerpo.origen === cuerpo.destino) e.destino = 'El origen y el destino deben ser distintos.'
    if (Object.keys(e).length) return invalido(e)
    if (sim.rutas.some((x) => x.origen === cuerpo.origen && x.destino === cuerpo.destino && x.tipoEnvio === cuerpo.tipoEnvio && x.vigenteHasta === null)) {
      return error(409, 'tarifa_vigente_existente', 'Esa ruta ya tiene una tarifa vigente para ese tipo. Usa «Nuevo precio» sobre la fila actual.')
    }
    const nueva = { id: sim.siguienteRuta++, origen: cuerpo.origen, destino: cuerpo.destino, tipoEnvio: cuerpo.tipoEnvio, precioBase: Number(norm(cuerpo.precioBase)).toFixed(2), precioPorKm: Number(norm(cuerpo.precioPorKm)).toFixed(3),
      distanciaKm: Number(norm(cuerpo.distanciaKm)).toFixed(1), tiempoHoras: cuerpo.tiempoHoras, vigenteDesde: HOY, vigenteHasta: null }
    sim.rutas.push(nueva)
    return exito({ ruta: presentarRuta(nueva) }, 201)
  }
  const precio = ruta.match(/^\/api\/catalogos\/rutas\/(\d+)\/precio$/)
  if (precio) {
    const actual = sim.rutas.find((x) => x.id === Number(precio[1]))
    if (!actual) return error(404, 'ruta_no_encontrada', 'No se encontró la ruta.')
    const e = erroresPrecio(cuerpo)
    if (Object.keys(e).length) return invalido(e)
    if (actual.vigenteHasta !== null) return error(409, 'tarifa_cerrada', 'Esa tarifa ya no está vigente. Cambia el precio de la fila vigente.')
    if (actual.vigenteDesde >= HOY) return error(409, 'tarifa_cambiada_hoy', 'Esa tarifa entró en vigor hoy; el nuevo precio puede registrarse a partir de mañana.')
    actual.vigenteHasta = '2026-10-31'
    const nueva = { ...actual, id: sim.siguienteRuta++, precioBase: Number(norm(cuerpo.precioBase)).toFixed(2), precioPorKm: Number(norm(cuerpo.precioPorKm)).toFixed(3), distanciaKm: Number(norm(cuerpo.distanciaKm)).toFixed(1),
      tiempoHoras: cuerpo.tiempoHoras, vigenteDesde: HOY, vigenteHasta: null }
    sim.rutas.push(nueva)
    return exito({ ruta: presentarRuta(nueva) }, 201)
  }

  // ── Vehículos ────────────────────────────────────────────────────────────
  if (ruta === '/api/catalogos/vehiculos' && metodo === 'GET') return sim.fallas.vehiculos ? falla : exito({ vehiculos: sim.vacio ? [] : sim.vehiculos })
  if (ruta === '/api/catalogos/vehiculos' && metodo === 'POST') {
    const e = {}
    if (!/^[A-Za-z0-9]{3}-\d{3}$/.test(cuerpo.placa ?? '')) e.placa = 'La placa tiene el formato ABC-123.'
    if (!esDecimal(cuerpo.capacidadKg, 7, 2) || Number(norm(cuerpo.capacidadKg)) <= 0) e.capacidadKg = 'La capacidad es mayor que 0 kg (hasta 2 decimales).'
    if (Object.keys(e).length) return invalido(e)
    if (sim.vehiculos.some((x) => x.placa === cuerpo.placa.toUpperCase())) return error(409, 'vehiculo_existente', 'Ya existe un vehículo con esa placa.', { placa: 'Ya existe un vehículo con esa placa.' })
    const nuevo = { placa: cuerpo.placa.toUpperCase(), tipoVehiculo: cuerpo.tipoVehiculo, capacidadKg: Number(norm(cuerpo.capacidadKg)).toFixed(2), activo: true, viajesAbiertos: 0 }
    sim.vehiculos.push(nuevo)
    return exito({ vehiculo: nuevo }, 201)
  }
  const veh = ruta.match(/^\/api\/catalogos\/vehiculos\/([A-Z0-9-]+)(\/estado)?$/)
  if (veh) {
    const v = sim.vehiculos.find((x) => x.placa === veh[1])
    if (!v) return error(404, 'vehiculo_no_encontrado', 'No se encontró el vehículo.')
    if (veh[2]) {
      if (!cuerpo.activo && v.viajesAbiertos > 0) return error(409, 'vehiculo_con_viajes', `No se puede desactivar: tiene ${v.viajesAbiertos} viaje(s) programado(s) o en ruta.`)
      v.activo = cuerpo.activo
      return exito({ vehiculo: v })
    }
    if (Number(norm(cuerpo.capacidadKg)) < 900 && v.placa === 'B7K-210') return error(409, 'capacidad_menor_a_la_carga', 'La capacidad no puede ser menor que la carga de sus viajes sin llegada (900.00 kg).', { capacidadKg: 'Es menor que la carga ya asignada.' })
    Object.assign(v, { tipoVehiculo: cuerpo.tipoVehiculo, capacidadKg: Number(norm(cuerpo.capacidadKg)).toFixed(2) })
    return exito({ vehiculo: v })
  }

  // ── Cuentas ──────────────────────────────────────────────────────────────
  if (ruta === '/api/usuarios' && metodo === 'GET') {
    if (sim.fallas.cuentas) return falla
    const texto = q('q').toLowerCase()
    const filas = sim.vacio ? [] : sim.cuentas.filter((c) => (!q('rol') || c.rol === q('rol')) && (!q('estado') || c.activa === (q('estado') === 'ACTIVA')) && (!texto || c.usuario.includes(texto) || c.persona.toLowerCase().includes(texto)))
    return exito({ cuentas: filas.map((c) => ({ ...c, esUsted: c.usuario === 'rquispe', editable: ['ADMINISTRADOR', 'TRANSPORTISTA'].includes(c.rol) })), hayMas: false }, 200, sim.cuentasLentas !== null && texto === sim.cuentasLentas ? RETRASO_MS : 0)
  }
  if (ruta === '/api/usuarios/persona') {
    const numero = q('numeroDocumento')
    if (numero === '74561230') return exito({ persona: { existe: true, nombreCompleto: 'Juan Condori Apaza', tieneCuenta: false } })
    if (numero === '71889345') return exito({ persona: { existe: true, nombreCompleto: 'María Elena Rojas Ccori', tieneCuenta: true } })
    return exito({ persona: { existe: false } })
  }
  if (ruta === '/api/usuarios' && metodo === 'POST') {
    const e = {}
    if (!cuerpo.sucursal) e.sucursal = 'La sucursal es obligatoria para este rol.'
    if (!/^[A-Za-z0-9._-]{4,30}$/.test(cuerpo.usuario ?? '')) e.usuario = 'El usuario debe tener de 4 a 30 caracteres: letras, números, punto, guion o guion bajo.'
    if ((cuerpo.contrasena ?? '').length < 8) e.contrasena = 'La contraseña debe tener al menos 8 caracteres.'
    if (Object.keys(e).length) return invalido(e)
    if (cuerpo.numeroDocumento !== '74561230') return error(404, 'persona_no_encontrada', 'No hay una persona registrada con ese documento. Debe existir antes de crearle una cuenta.', { numeroDocumento: 'No hay una persona registrada con ese documento.' })
    if (sim.cuentas.some((c) => c.usuario === cuerpo.usuario)) return error(409, 'usuario_en_uso', 'Ese usuario ya está en uso.', { usuario: 'Ese usuario ya está en uso. Elige otro.' })
    const nueva = { usuario: cuerpo.usuario, persona: 'Juan Condori Apaza', rol: cuerpo.rol, sucursal: { codigo: cuerpo.sucursal, nombre: `Agencia ${cuerpo.sucursal}` }, activa: true }
    sim.cuentas.push(nueva)
    return exito({ cuenta: { ...nueva, esUsted: false, editable: true } }, 201)
  }
  const cta = ruta.match(/^\/api\/usuarios\/([A-Za-z0-9._-]+)(\/estado)?$/)
  if (cta) {
    const c = sim.cuentas.find((x) => x.usuario === cta[1])
    if (!c) return error(404, 'cuenta_no_encontrada', 'No se encontró la cuenta.')
    if (cta[2]) {
      if (!cuerpo.activo) {
        if (c.usuario === 'rquispe') return error(409, 'no_puede_desactivarse', 'No puedes desactivar tu propia cuenta.')
        if (c.usuario === 'pmamani') return error(409, 'transportista_con_viajes', 'No se puede desactivar: tiene 1 viaje(s) programado(s) o en ruta.')
      }
      if (cuerpo.activo && sim.bloqueoReactivar === c.usuario) return error(409, 'sucursal_inactiva', 'No se puede reactivar: su sucursal (MOQ001) está desactivada. Reactiva la sucursal o muévela a una agencia activa con «Editar» y vuelve a intentarlo.')
      c.activa = cuerpo.activo
      return exito({ cuenta: { ...c, esUsted: false, editable: true } })
    }
    if (!['ADMINISTRADOR', 'TRANSPORTISTA'].includes(c.rol)) return error(409, 'cuenta_no_editable', 'Solo se edita el puesto del personal (administradores y transportistas).')
    if (!cuerpo.sucursal) return invalido({ sucursal: 'La sucursal es obligatoria para este rol.' })
    Object.assign(c, { rol: cuerpo.rol, sucursal: { codigo: cuerpo.sucursal, nombre: `Agencia ${cuerpo.sucursal}` } })
    return exito({ cuenta: { ...c, esUsted: false, editable: true } })
  }
  return null
})
const { escribir, pulsar, texto, valor, existe, ruta, evaluar, llamadas } = navegador

// Espera CONDICIONES, no un tiempo fijo: la pantalla queda «estable» cuando ya pintó algo, no hay indicador de carga y su texto no
// cambia entre dos lecturas seguidas. Las pruebas de carreras conservan sus esperas fijas (ahí el tiempo es parte de lo probado).
async function estable(minimoMs = 250, limiteMs = 10000) {
  await esperar(minimoMs)
  const inicio = Date.now()
  let anterior = null
  while (Date.now() - inicio < limiteMs) {
    const estado = await evaluar(`JSON.stringify({ cargando: !!document.querySelector('[role=status].cargando, .cargando'), texto: document.body.innerText })`)
    const lectura = estado ? JSON.parse(estado) : null
    if (lectura && !lectura.cargando && lectura.texto.trim().length > 0 && estado === anterior) return
    anterior = estado
    await esperar(150)
  }
  throw new Error('La pantalla no se estabilizó a tiempo')
}
const irA = async (rutaNueva) => { await navegador.irA(rutaNueva, 100); await estable(300) }
const filas = (selector) => evaluar(`[...document.querySelectorAll(${JSON.stringify(selector)})].map(r => r.innerText.replace(/\\s+/g, ' ').trim()).join(' || ')`)
const aviso = () => texto('.resultado:not(.aviso-tarifas)')
const llamadasA = (metodo, rutaApi) => llamadas.filter((l) => l.metodo === metodo && l.ruta === rutaApi)
const tabla = (nombre) => filas(`table[class*="tabla-catalogo"] tbody tr${nombre ?? ''}`)

try {
  titulo('Catálogos: Rutas y precios (diapositiva 29)')
  await irA('/gerente/catalogos')
  comprobar('título y pestañas; «Rutas y precios» es la pestaña inicial', [await texto('h1'), await evaluar(`[...document.querySelectorAll('[role=tab]')].map(t => t.innerText + ':' + t.getAttribute('aria-selected')).join(' ')`)], ['Catálogos', 'Sucursales:false Rutas y precios:true Vehículos:false'])
  comprobar('las cinco tarifas vigentes con su costo del bulto (S/ 9.15, 14.60, 30.20, 136.00 y 8.50)', await tabla(),
    'MOQ001 → AQP001 Sin tarifa vigente: CARGA Agregar CARGA SOBRE 8.00 0.005 230.0 4 01/09/2026 vigente S/ 9.15 Nuevo precio || MOQ001 → AQP001 Sin tarifa vigente: CARGA Agregar CARGA PAQUETE 10.00 0.020 230.0 4 01/09/2026 vigente S/ 14.60 Nuevo precio || AQP001 → LIM001 Sin tarifa vigente: SOBRE, CARGA Agregar SOBRE PAQUETE 10.00 0.020 1010.0 18 01/09/2026 vigente S/ 30.20 Nuevo precio || LIM001 → ILO001 Sin tarifa vigente: SOBRE, PAQUETE Agregar SOBRE CARGA 40.00 0.080 1200.0 24 01/09/2026 vigente S/ 136.00 Nuevo precio || ILO001 → MOQ001 Sin tarifa vigente: PAQUETE, CARGA Agregar PAQUETE SOBRE 8.00 0.005 100.0 2 01/09/2026 vigente S/ 8.50 Nuevo precio')
  comprobar('la nota de la diapositiva sobre «Nuevo precio»', (await texto('section[aria-label="Rutas y precios"] p.ayuda:last-of-type')).startsWith('Nuevo precio: se cierra la vigencia de la fila actual y se crea otra. Los bultos ya cobrados conservan su tarifa.'), true)
  comprobar('la tabla tiene un título accesible', await texto('caption'), 'Rutas con sus precios y vigencia')

  titulo('Catálogos: «Nuevo precio» (cierra la vigencia y crea otra fila)')
  await pulsar('button[aria-label="Nuevo precio MOQ001 a AQP001 SOBRE"]'); await estable()
  comprobar('el formulario trae los valores actuales y explica la regla', [await texto('form[aria-label="Nuevo precio"] h3'), await valor('#precio-base'), await valor('#precio-km-precio'), await valor('#precio-km'), await valor('#precio-horas')], ['Nuevo precio · MOQ001 → AQP001 · SOBRE', '8.00', '0.005', '230.0', '4'])
  await escribir('#precio-base', '0'); await escribir('#precio-horas', '0'); await pulsar('form[aria-label="Nuevo precio"] button[type=submit]'); await estable()
  comprobar('datos inválidos: el servidor marca cada campo y no se guarda nada', [await texto('#precio-base-error'), await texto('#precio-horas-error'), llamadasA('POST', '/api/catalogos/rutas/1/precio').length], ['El precio base es mayor que 0 (hasta 2 decimales).', 'Las horas son un entero de 1 en adelante.', 1])
  await escribir('#precio-base', '9,00'); await escribir('#precio-km-precio', '0.006'); await escribir('#precio-horas', '4'); await pulsar('form[aria-label="Nuevo precio"] button[type=submit]'); await estable()
  comprobar('el cuerpo enviado trae solo los precios (con las horas como número) y el aviso informa el costo nuevo', [llamadasA('POST', '/api/catalogos/rutas/1/precio').at(-1).cuerpo, await aviso()],
    [{ precioBase: '9,00', precioPorKm: '0.006', distanciaKm: '230.0', tiempoHoras: 4 }, 'Nuevo precio de MOQ001 → AQP001 (SOBRE): S/ 10.38 por bulto, vigente desde hoy. La tarifa anterior quedó cerrada.'])
  comprobar('la lista muestra la fila nueva vigente y la vieja desaparece de las vigentes', [(await tabla()).split(' || ').length, (await tabla()).includes('MOQ001 → AQP001 Sin tarifa vigente: CARGA Agregar CARGA SOBRE 9.00 0.006 230.0 4 01/11/2026 vigente S/ 10.38 Nuevo precio')], [5, true])
  await pulsar('.casilla input'); await estable()
  comprobar('«Ver tarifas cerradas» conserva el historial: la fila vieja con su precio y su fecha de cierre', (await tabla()).includes('MOQ001 → AQP001 SOBRE 8.00 0.005 230.0 4 01/09/2026 31/10/2026 S/ 9.15'), true)
  await pulsar('button[aria-label="Nuevo precio MOQ001 a AQP001 SOBRE"]'); await estable()
  await pulsar('form[aria-label="Nuevo precio"] button[type=submit]'); await estable()
  comprobar('cambiar el precio otra vez el mismo día: el servidor lo rechaza y la pantalla lo explica', await aviso(), 'Esa tarifa entró en vigor hoy; el nuevo precio puede registrarse a partir de mañana.')
  await pulsar('form[aria-label="Nuevo precio"] button.boton-claro'); await estable()
  comprobar('«Cancelar» cierra el formulario', await existe('form[aria-label="Nuevo precio"]'), false)
  await pulsar('.casilla input'); await estable()

  titulo('Catálogos: «Nueva ruta»')
  await pulsar('section[aria-label="Rutas y precios"] .barra-historial button.boton-azul'); await estable()
  comprobar('las agencias del formulario son solo las ACTIVAS (sin Tacna)', await evaluar(`[...document.querySelectorAll('#ruta-origen option')].map(o => o.innerText).join(',')`), 'Elige…,Agencia Arequipa Centro,Agencia Ilo,Agencia Lima Centro,Agencia Moquegua')
  await pulsar('form[aria-label="Nueva ruta"] button[type=submit]'); await estable()
  comprobar('formulario vacío: cada campo muestra su error', [await texto('#ruta-origen-error'), await texto('#ruta-destino-error'), await texto('#ruta-base-error'), await texto('#ruta-horas-error')],
    ['Elige la agencia de origen.', 'Elige la agencia de destino.', 'El precio base es mayor que 0 (hasta 2 decimales).', 'Las horas son un entero de 1 en adelante.'])
  const completar = async (origen, destino, tipo) => {
    await escribir('#ruta-origen', origen); await escribir('#ruta-destino', destino); await escribir('#ruta-tipo', tipo)
    await escribir('#ruta-base', '12,50'); await escribir('#ruta-km-precio', '0.025'); await escribir('#ruta-km', '330.5'); await escribir('#ruta-horas', '5')
  }
  await completar('ILO001', 'ILO001', 'PAQUETE'); await pulsar('form[aria-label="Nueva ruta"] button[type=submit]'); await estable()
  comprobar('origen = destino: error en el destino', await texto('#ruta-destino-error'), 'El origen y el destino deben ser distintos.')
  await completar('MOQ001', 'AQP001', 'SOBRE'); await pulsar('form[aria-label="Nueva ruta"] button[type=submit]'); await estable()
  comprobar('una ruta con tarifa vigente repetida: el servidor la rechaza y sugiere «Nuevo precio»', await aviso(), 'Esa ruta ya tiene una tarifa vigente para ese tipo. Usa «Nuevo precio» sobre la fila actual.')
  await completar('ILO001', 'AQP001', 'PAQUETE'); await pulsar('form[aria-label="Nueva ruta"] button[type=submit]'); await estable()
  comprobar('ruta nueva: aviso, formulario cerrado y fila vigente desde hoy', [await aviso(), await existe('form[aria-label="Nueva ruta"]'), (await tabla()).includes('ILO001 → AQP001 Sin tarifa vigente: SOBRE, CARGA Agregar SOBRE PAQUETE 12.50 0.025 330.5 5 01/11/2026 vigente S/ 20.76 Nuevo precio')],
    ['Ruta ILO001 → AQP001 (PAQUETE) creada, vigente desde hoy.', false, true])

  titulo('Catálogos: Sucursales')
  await pulsar('[role=tab]#pestana-sucursales'); await estable()
  comprobar('la pestaña va en la dirección y la lista incluye las inactivas', [await ruta(), (await tabla()).split(' || ').length, (await tabla()).includes('TAC001 Agencia Tacna')], ['/gerente/catalogos?pestana=sucursales', 5, true])
  comprobar('cada sucursal muestra ubicación, teléfonos (con formato) y estado', (await tabla()).split(' || ')[0], 'AQP001 Agencia Arequipa Centro Dist, Prov, Dep · Av. Principal 100 953 123 456 ACTIVA Editar Desactivar')
  await pulsar('section[aria-label="Sucursales"] .barra-historial button'); await estable()
  await escribir('#suc-codigo', 'xx1'); await pulsar('form[aria-label="Nueva sucursal"] button[type=submit]'); await estable()
  comprobar('alta inválida: errores del servidor en el código y el nombre', [await texto('#suc-codigo-error'), await texto('#suc-nombre-error')], ['El código son tres letras y tres dígitos. Por ejemplo: AQP001.', 'Ingresa el nombre.'])
  await escribir('#suc-codigo', 'moq001'); await escribir('#suc-nombre', 'Otra'); await pulsar('form[aria-label="Nueva sucursal"] button[type=submit]'); await estable()
  comprobar('código repetido: 409 en el campo', await texto('#suc-codigo-error'), 'Ya existe una sucursal con ese código.')
  await escribir('#suc-codigo', 'cus001'); await escribir('#suc-nombre', 'Agencia Cusco'); await escribir('#suc-telefonos', '984111222, 084222333'); await pulsar('form[aria-label="Nueva sucursal"] button[type=submit]'); await estable()
  comprobar('el cuerpo envía los teléfonos como lista y el aviso confirma', [llamadasA('POST', '/api/catalogos/sucursales').at(-1).cuerpo.telefonos, await aviso()], [['984111222', '084222333'], 'Sucursal CUS001 creada.'])
  await pulsar('button[aria-label="Editar CUS001"]'); await estable()
  comprobar('editar: el código no se puede cambiar y trae los datos', [await evaluar(`document.querySelector('#suc-codigo').disabled`), await valor('#suc-nombre'), await valor('#suc-telefonos')], [true, 'Agencia Cusco', '984111222, 084222333'])
  await escribir('#suc-nombre', 'Agencia Cusco Plaza'); await pulsar('form[aria-label="Editar sucursal"] button[type=submit]'); await estable()
  comprobar('guardar la edición', [await aviso(), (await tabla()).includes('CUS001 Agencia Cusco Plaza')], ['Sucursal CUS001 actualizada.', true])
  await pulsar('button[aria-label="Desactivar MOQ001"]'); await estable()
  comprobar('desactivar pide confirmación antes de llamar al servidor', [await evaluar(`[...document.querySelectorAll('td.celda-accion button')].some(b => b.innerText === 'Confirmar')`), llamadasA('POST', '/api/catalogos/sucursales/MOQ001/estado').length], [true, 0])
  await evaluar(`[...document.querySelectorAll('td.celda-accion button')].find(b => b.innerText === 'Confirmar').click()`); await estable()
  comprobar('el servidor se niega (tiene personal activo) y la pantalla explica por qué; la sucursal sigue activa', [await aviso(), (await tabla()).includes('MOQ001 Agencia Moquegua') && !(await tabla()).split(' || ').find((f) => f.startsWith('MOQ001')).includes('INACTIVA')],
    ['No se puede desactivar: tiene 2 cuenta(s) de personal activa(s). Desactívalas o muévelas a otra sucursal primero.', true])
  await pulsar('button[aria-label="Desactivar CUS001"]'); await estable()
  await evaluar(`[...document.querySelectorAll('td.celda-accion button')].find(b => b.innerText === 'Confirmar').click()`); await estable()
  comprobar('una sucursal sin nada en uso se desactiva y se puede activar de nuevo (sin confirmación)', [await aviso(), (await tabla()).split(' || ').find((f) => f.startsWith('CUS001')).includes('INACTIVA')], ['Sucursal CUS001 desactivada.', true])
  await pulsar('button[aria-label="Activar CUS001"]'); await estable()
  comprobar('reactivar', await aviso(), 'Sucursal CUS001 activada.')

  titulo('Catálogos: Vehículos')
  await pulsar('[role=tab]#pestana-vehiculos'); await estable()
  comprobar('lista con tipo, capacidad, viajes abiertos y estado', await tabla(), 'B7K-210 FURGON 1500.00 kg 0 ACTIVA Editar Desactivar || C3M-956 CAMION 18000.00 kg 1 ACTIVA Editar Desactivar')
  await pulsar('section[aria-label="Vehículos"] .barra-historial button'); await estable()
  await escribir('#veh-placa', 'AB-12'); await escribir('#veh-capacidad', '0'); await pulsar('form[aria-label="Nuevo vehículo"] button[type=submit]'); await estable()
  comprobar('alta inválida: errores en la placa y la capacidad', [await texto('#veh-placa-error'), await texto('#veh-capacidad-error')], ['La placa tiene el formato ABC-123.', 'La capacidad es mayor que 0 kg (hasta 2 decimales).'])
  await escribir('#veh-placa', 'b7k-210'); await escribir('#veh-capacidad', '500'); await pulsar('form[aria-label="Nuevo vehículo"] button[type=submit]'); await estable()
  comprobar('placa repetida: 409 en el campo', await texto('#veh-placa-error'), 'Ya existe un vehículo con esa placa.')
  await escribir('#veh-placa', 'v2a-845'); await escribir('#veh-tipo', 'CAMIONETA'); await escribir('#veh-capacidad', '800,5'); await pulsar('form[aria-label="Nuevo vehículo"] button[type=submit]'); await estable()
  comprobar('vehículo nuevo creado con la placa en mayúsculas', [await aviso(), (await tabla()).includes('V2A-845 CAMIONETA 800.50 kg 0 ACTIVA')], ['Vehículo V2A-845 creado.', true])
  await pulsar('button[aria-label="Editar B7K-210"]'); await estable()
  comprobar('editar: la placa no se puede cambiar', await evaluar(`document.querySelector('#veh-placa').disabled`), true)
  await escribir('#veh-capacidad', '500'); await pulsar('form[aria-label="Editar vehículo"] button[type=submit]'); await estable()
  comprobar('bajar la capacidad por debajo de la carga: 409 en el campo', await texto('#veh-capacidad-error'), 'Es menor que la carga ya asignada.')
  await escribir('#veh-capacidad', '2000'); await pulsar('form[aria-label="Editar vehículo"] button[type=submit]'); await estable()
  comprobar('capacidad válida: se guarda', [await aviso(), (await tabla()).includes('B7K-210 FURGON 2000.00 kg')], ['Vehículo B7K-210 actualizado.', true])
  await pulsar('button[aria-label="Desactivar C3M-956"]'); await estable()
  await evaluar(`[...document.querySelectorAll('td.celda-accion button')].find(b => b.innerText === 'Confirmar').click()`); await estable()
  comprobar('un vehículo con viajes sin llegada no se desactiva: el servidor explica por qué', await aviso(), 'No se puede desactivar: tiene 1 viaje(s) programado(s) o en ruta.')

  titulo('Catálogos: estados de error, vacío y reintento')
  sim.fallas.rutas = true
  await irA('/gerente/catalogos?pestana=rutas')
  comprobar('si falla: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudieron cargar las rutas. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallas.rutas = false
  await pulsar('.resultado.error button'); await estable()
  comprobar('«Reintentar» recarga la tabla', (await tabla()).split(' || ').length >= 5, true)
  sim.vacio = true
  await irA('/gerente/catalogos?pestana=rutas')
  comprobar('sin tarifas: mensaje de vacío', await texto('.estado-vacio'), 'Todavía no hay tarifas vigentes. Crea la primera ruta.')
  await irA('/gerente/catalogos?pestana=sucursales')
  comprobar('sin sucursales: mensaje de vacío', await texto('.estado-vacio'), 'Todavía no hay sucursales. Crea la primera.')
  await irA('/gerente/catalogos?pestana=vehiculos')
  comprobar('sin vehículos: mensaje de vacío', await texto('.estado-vacio'), 'Todavía no hay vehículos. Registra el primero.')
  sim.vacio = false

  titulo('Cuentas (diapositiva 30)')
  await irA('/gerente/cuentas')
  comprobar('la lista reproduce la diapositiva 30, sin contraseñas, documentos ni teléfonos', await tabla(),
    'rquispe (usted) Rosa María Quispe Mamani GERENTE — ACTIVA || lflores Luis Alberto Flores Condori ADMINISTRADOR MOQ001 ACTIVA Editar || mrojas María Elena Rojas Ccori CLIENTE — ACTIVA || comsur Comercial Sur S.A.C. EMPRESARIO — ACTIVA || pmamani Pedro Mamani Huanca TRANSPORTISTA AQP001 ACTIVA Editar')
  comprobar('solo el personal tiene «Editar»', await evaluar(`document.querySelectorAll('td.celda-accion button').length`), 2)
  comprobar('«Desactivar seleccionada» está apagado hasta elegir una cuenta', [await evaluar(`document.querySelector('.cuentas .acciones-formulario button').disabled`), await texto('.cuentas .acciones-formulario .ayuda')], [true, 'Elige una cuenta de la lista.'])
  await escribir('#cuenta-rol', 'CLIENTE'); await estable()
  comprobar('filtrar por rol consulta el servidor con el filtro', [llamadasA('GET', '/api/usuarios').at(-1).url ?? llamadasA('GET', '/api/usuarios').at(-1).ruta, (await tabla()).split(' || ').length], [llamadasA('GET', '/api/usuarios').at(-1).url ?? '/api/usuarios', 1])
  await escribir('#cuenta-rol', ''); await escribir('#cuenta-q', 'pedro'); await estable(700)
  comprobar('buscar por nombre (con un instante de espera tras la última tecla)', await tabla(), 'pmamani Pedro Mamani Huanca TRANSPORTISTA AQP001 ACTIVA Editar')
  await escribir('#cuenta-q', 'zzz'); await estable(700)
  comprobar('sin coincidencias: mensaje con los filtros', await texto('.estado-vacio'), 'No hay cuentas que coincidan con esos filtros.')
  await escribir('#cuenta-q', ''); await estable(700)

  titulo('Cuentas: carrera entre dos búsquedas')
  sim.cuentasLentas = 'rosa'
  await escribir('#cuenta-q', 'rosa'); await esperar(500)
  await escribir('#cuenta-q', 'pedro'); await esperar(900)
  comprobar('se muestra el resultado de la última búsqueda', await tabla(), 'pmamani Pedro Mamani Huanca TRANSPORTISTA AQP001 ACTIVA Editar')
  await esperar(RETRASO_MS + 300)
  comprobar('la respuesta tardía de la anterior no la pisa', await tabla(), 'pmamani Pedro Mamani Huanca TRANSPORTISTA AQP001 ACTIVA Editar')
  sim.cuentasLentas = null
  await escribir('#cuenta-q', ''); await estable(700)

  titulo('Cuentas: «Desactivar seleccionada»')
  await pulsar('input[aria-label="Elegir rquispe"]')
  await pulsar('.cuentas .acciones-formulario button'); await estable()
  await pulsar('.cuentas .acciones-formulario button.boton-peligro'); await estable()
  comprobar('no puede desactivar su propia cuenta: el servidor lo rechaza y lo explica', await aviso(), 'No puedes desactivar tu propia cuenta.')
  await pulsar('input[aria-label="Elegir pmamani"]'); await pulsar('.cuentas .acciones-formulario button'); await estable()
  comprobar('pide confirmación e indica qué cuenta', await texto('.cuentas .acciones-formulario button.boton-peligro'), 'Confirmar desactivación de pmamani')
  await pulsar('.cuentas .acciones-formulario button.boton-peligro'); await estable()
  comprobar('un transportista con viajes no se desactiva', await aviso(), 'No se puede desactivar: tiene 1 viaje(s) programado(s) o en ruta.')
  await pulsar('input[aria-label="Elegir lflores"]'); await pulsar('.cuentas .acciones-formulario button'); await estable()
  await pulsar('.cuentas .acciones-formulario button.boton-peligro'); await estable()
  comprobar('una cuenta común se desactiva y la lista lo muestra', [await aviso(), (await tabla()).split(' || ')[1].includes('INACTIVA')], ['Cuenta lflores desactivada.', true])
  sim.bloqueoReactivar = 'lflores'
  await pulsar('.cuentas .acciones-formulario button'); await estable()
  comprobar('reactivar personal de una sucursal desactivada: el servidor se niega, la pantalla explica qué hacer y la cuenta sigue inactiva', [await aviso(), (await tabla()).split(' || ')[1].includes('INACTIVA')],
    ['No se puede reactivar: su sucursal (MOQ001) está desactivada. Reactiva la sucursal o muévela a una agencia activa con «Editar» y vuelve a intentarlo.', true])
  sim.bloqueoReactivar = null
  comprobar('con una cuenta inactiva seleccionada el botón pasa a «Activar seleccionada»', await texto('.cuentas .acciones-formulario button'), 'Activar seleccionada')
  await pulsar('.cuentas .acciones-formulario button'); await estable()
  comprobar('activar la cuenta de nuevo', [await aviso(), (await tabla()).split(' || ')[1].includes('INACTIVA')], ['Cuenta lflores activada.', false])

  titulo('Cuentas: «Editar»')
  await pulsar('button[aria-label="Editar lflores"]'); await estable()
  comprobar('el formulario trae el puesto actual', [await texto('form[aria-label="Editar cuenta"] h2'), await valor('#edicion-rol'), await valor('#edicion-sucursal')], ['Editar lflores', 'ADMINISTRADOR', 'MOQ001'])
  await escribir('#edicion-sucursal', ''); await pulsar('form[aria-label="Editar cuenta"] button[type=submit]'); await estable()
  comprobar('sin sucursal: el servidor marca el campo', await texto('#edicion-sucursal-error'), 'La sucursal es obligatoria para este rol.')
  await escribir('#edicion-sucursal', 'AQP001'); await pulsar('form[aria-label="Editar cuenta"] button[type=submit]'); await estable()
  comprobar('cambio de sucursal guardado', [await aviso(), (await tabla()).split(' || ')[1]], ['Cuenta lflores actualizada: ADMINISTRADOR en AQP001.', 'lflores Luis Alberto Flores Condori ADMINISTRADOR AQP001 ACTIVA Editar'])

  titulo('Cuentas: «Nueva cuenta de personal»')
  comprobar('la contraseña no se muestra (campo password) y el formulario pide el documento de la persona', [await evaluar(`document.querySelector('#nueva-contrasena').type`), await texto('label[for=nueva-documento]'), await texto('label[for=nueva-sucursal]')], ['password', 'Documento de la persona', 'Sucursal (obligatoria para este rol)'])
  await escribir('#nueva-documento', '74561230'); await estable()
  comprobar('al completar el DNI se muestra la persona encontrada', await texto('.estado-persona'), 'Persona: Juan Condori Apaza')
  await escribir('#nueva-documento', '71889345'); await estable()
  comprobar('si la persona ya tiene cuenta, lo dice', await texto('.estado-persona'), 'María Elena Rojas Ccori ya tiene una cuenta.')
  await escribir('#nueva-documento', '11111111'); await estable()
  comprobar('si la persona no existe, lo dice', await texto('.estado-persona'), 'No hay una persona registrada con ese documento: debe existir antes de crearle una cuenta.')
  await escribir('#nueva-documento', '74561230'); await estable()
  await pulsar('form[aria-label="Nueva cuenta de personal"] button[type=submit]'); await estable()
  comprobar('formulario incompleto: errores del servidor por campo', [await texto('#nueva-sucursal-error'), await texto('#nueva-usuario-error'), await texto('#nueva-contrasena-error')],
    ['La sucursal es obligatoria para este rol.', 'El usuario debe tener de 4 a 30 caracteres: letras, números, punto, guion o guion bajo.', 'La contraseña debe tener al menos 8 caracteres.'])
  await escribir('#nueva-rol', 'TRANSPORTISTA'); await escribir('#nueva-sucursal', 'AQP001'); await escribir('#nueva-usuario', 'lflores'); await escribir('#nueva-contrasena', 'Temporal#2026')
  await pulsar('form[aria-label="Nueva cuenta de personal"] button[type=submit]'); await estable()
  comprobar('usuario repetido: 409 en el campo', await texto('#nueva-usuario-error'), 'Ese usuario ya está en uso. Elige otro.')
  await escribir('#nueva-usuario', 'jcondori'); await pulsar('form[aria-label="Nueva cuenta de personal"] button[type=submit]'); await estable()
  comprobar('cuenta creada: aviso, formulario limpio y fila nueva', [await aviso(), await valor('#nueva-usuario'), await valor('#nueva-contrasena'), (await tabla()).includes('jcondori Juan Condori Apaza TRANSPORTISTA AQP001 ACTIVA Editar')],
    ['Cuenta jcondori creada (TRANSPORTISTA, AQP001). Entrega la contraseña temporal en persona.', '', '', true])
  comprobar('el cuerpo enviado lleva el documento sin separadores y nunca se muestra la contraseña en pantalla', [llamadasA('POST', '/api/usuarios').at(-1).cuerpo.numeroDocumento, (await evaluar('document.body.innerText')).includes('Temporal#2026')], ['74561230', false])

  titulo('Cuentas: errores, vacío y accesos por rol')
  sim.fallas.cuentas = true
  await irA('/gerente/cuentas')
  comprobar('si falla: aviso con «Reintentar»', await texto('.resultado.error'), 'No se pudieron cargar las cuentas. Ocurrió un error interno. Inténtalo de nuevo más tarde. Reintentar')
  sim.fallas.cuentas = false
  await pulsar('.resultado.error button'); await estable()
  comprobar('«Reintentar» recarga', (await tabla()).split(' || ').length >= 5, true)
  sim.vacio = true
  await irA('/gerente/cuentas')
  comprobar('sin cuentas: mensaje de vacío', await texto('.estado-vacio'), 'Todavía no hay cuentas.')
  sim.vacio = false
  sim.sesion = null
  await irA('/gerente/catalogos')
  comprobar('sin sesión: Catálogos pide ingresar', (await ruta()).startsWith('/ingresar'), true)
  sim.sesion = ADMIN
  await irA('/gerente/cuentas')
  comprobar('un administrador no entra a Cuentas (lo manda a Ventanilla)', await ruta(), '/administrador')
  sim.sesion = GERENTE
  await irA('/gerente/cuentas')
  comprobar('el menú del gerente marca Cuentas', await evaluar(`[...document.querySelectorAll('nav.menu a')].map(a => a.innerText + '→' + a.getAttribute('href')).filter(x => x.startsWith('Cuentas') || x.startsWith('Catálogos')).join(' ')`), 'Catálogos→/gerente/catalogos Cuentas→/gerente/cuentas')

  if (process.env.CAPTURAS) {
    titulo('Móvil (solo con CAPTURAS)')
    for (const [pantalla, nombre] of [['/gerente/catalogos?pestana=rutas', 'm_catalogos_rutas'], ['/gerente/catalogos?pestana=sucursales', 'm_catalogos_sucursales'], ['/gerente/cuentas', 'm_cuentas']]) {
      await irA(pantalla)
      await navegador.captura(`${process.env.CAPTURAS}/${nombre}.png`, 390, 844)
      comprobar(`${nombre}: sin desplazamiento horizontal`, await evaluar('document.documentElement.scrollWidth <= window.innerWidth'), true)
    }
  }
} finally {
  await navegador.cerrar()
}
terminar()
