import { solicitar } from './cliente.js'


// GET /api/catalogos/sucursales — { sucursales } (activas e inactivas, con teléfonos).
export function listarSucursales(signal) {
  return solicitar('/catalogos/sucursales', { signal })
}

// POST /api/catalogos/sucursales — crea una sucursal. Devuelve la sucursal.
export async function crearSucursal(datos) {
  return (await solicitar('/catalogos/sucursales', { metodo: 'POST', cuerpo: datos })).sucursal
}

// PUT /api/catalogos/sucursales/{codigo} — edita datos y teléfonos (el código no cambia).
export async function actualizarSucursal(codigo, datos) {
  return (await solicitar(`/catalogos/sucursales/${encodeURIComponent(codigo)}`, { metodo: 'PUT', cuerpo: datos })).sucursal
}

// POST /api/catalogos/sucursales/{codigo}/estado — { activa: boolean }.
export async function cambiarEstadoSucursal(codigo, activa) {
  return (await solicitar(`/catalogos/sucursales/${encodeURIComponent(codigo)}/estado`, { metodo: 'POST', cuerpo: { activa } })).sucursal
}

// GET /api/catalogos/rutas — { rutas } con todas las tarifas (vigentes y cerradas) y el costo del bulto.
export function listarRutas(signal) {
  return solicitar('/catalogos/rutas', { signal })
}

// POST /api/catalogos/rutas — «Nueva ruta» (vigente desde hoy). Devuelve la ruta.
export async function crearRuta(datos) {
  return (await solicitar('/catalogos/rutas', { metodo: 'POST', cuerpo: datos })).ruta
}

// POST /api/catalogos/rutas/{id}/precio — «Nuevo precio»: cierra la vigencia de la fila y crea otra. Devuelve la fila nueva.
export async function nuevoPrecio(id, datos) {
  return (await solicitar(`/catalogos/rutas/${encodeURIComponent(id)}/precio`, { metodo: 'POST', cuerpo: datos })).ruta
}

// GET /api/catalogos/vehiculos — { vehiculos } con sus viajes sin llegada.
export function listarVehiculos(signal) {
  return solicitar('/catalogos/vehiculos', { signal })
}

// POST /api/catalogos/vehiculos — da de alta un vehículo.
export async function crearVehiculo(datos) {
  return (await solicitar('/catalogos/vehiculos', { metodo: 'POST', cuerpo: datos })).vehiculo
}

// PUT /api/catalogos/vehiculos/{placa} — cambia tipo y capacidad (la placa no cambia).
export async function actualizarVehiculo(placa, datos) {
  return (await solicitar(`/catalogos/vehiculos/${encodeURIComponent(placa)}`, { metodo: 'PUT', cuerpo: datos })).vehiculo
}

// POST /api/catalogos/vehiculos/{placa}/estado — { activo: boolean }.
export async function cambiarEstadoVehiculo(placa, activo) {
  return (await solicitar(`/catalogos/vehiculos/${encodeURIComponent(placa)}/estado`, { metodo: 'POST', cuerpo: { activo } })).vehiculo
}