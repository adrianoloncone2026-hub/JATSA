import { ROLES } from '../auth/roles.js'

// Mapa de navegación del prototipo (diapositiva 1). Cada pantalla indica el módulo de
// docs/orden-implementacion.md en el que se implementa. Por ahora todas son marcadores.
//   ruta:   relativa a la base de su sección ('' = pantalla inicial de la sección)
//   modulo: número de módulo donde se construye (0 = infraestructura)
//   menu:   si aparece en el menú de su layout
export const SECCIONES = [
  {
    id: 'publico',
    etiqueta: 'Público',
    base: '/',
    roles: [ROLES.VISITANTE],
    pantallas: [
      { ruta: '', titulo: 'Inicio', modulo: 2, menu: true },
      { ruta: 'rastreo', titulo: 'Rastrear', modulo: 9, menu: true },
      { ruta: 'salidas', titulo: 'Salidas', modulo: 9, menu: true },
      { ruta: 'salidas/:id', titulo: 'Detalle de salida', modulo: 9, menu: false },
      { ruta: 'agencias', titulo: 'Agencias', modulo: 2, menu: true },
      { ruta: 'cotizador', titulo: 'Cotizar', modulo: 2, menu: true },
      { ruta: 'crear-cuenta', titulo: 'Crear cuenta', modulo: 3, menu: true },
      { ruta: 'ingresar', titulo: 'Ingresar', modulo: 1, menu: true },
      { ruta: 'prueba-conexion', titulo: 'Prueba de conexión', modulo: 0, menu: false },
    ],
  },
  {
    id: 'cliente',
    etiqueta: 'Cliente / Empresario',
    base: '/cliente',
    roles: [ROLES.CLIENTE, ROLES.EMPRESARIO],
    pantallas: [
      { ruta: '', titulo: 'Mi panel', modulo: 4, menu: true },
      { ruta: 'nueva-reserva', titulo: 'Nueva reserva', modulo: 4, menu: true },
      { ruta: 'reserva-registrada/:codigo', titulo: 'Reserva registrada', modulo: 4, menu: false },
      { ruta: 'reservas', titulo: 'Mis reservas', modulo: 4, menu: true },
      { ruta: 'historial', titulo: 'Historial', modulo: 9, menu: true },
      { ruta: 'pagos', titulo: 'Mis pagos', modulo: 6, menu: true },
      { ruta: 'cuenta', titulo: 'Mi cuenta', modulo: 3, menu: true },
    ],
  },
  {
    id: 'administrador',
    etiqueta: 'Administrador',
    base: '/administrador',
    roles: [ROLES.ADMINISTRADOR],
    pantallas: [
      { ruta: '', titulo: 'Ventanilla', modulo: 5, menu: true },
      { ruta: 'formalizar-envio/:codigo', titulo: 'Formalizar envío', modulo: 5, menu: false },
      { ruta: 'caja', titulo: 'Caja', modulo: 6, menu: true },
      { ruta: 'despacho', titulo: 'Despacho', modulo: 7, menu: true },
      { ruta: 'llegadas-entregas', titulo: 'Llegadas y entregas', modulo: 8, menu: true },
      { ruta: 'reportes', titulo: 'Reportes', modulo: 12, menu: true },
    ],
  },
  {
    id: 'transportista',
    etiqueta: 'Transportista',
    base: '/transportista',
    roles: [ROLES.TRANSPORTISTA],
    pantallas: [
      { ruta: '', titulo: 'Mis viajes', modulo: 7, menu: true },
      { ruta: 'viajes/:id', titulo: 'Detalle del viaje', modulo: 7, menu: false },
    ],
  },
  {
    id: 'gerente',
    etiqueta: 'Gerente',
    base: '/gerente',
    roles: [ROLES.GERENTE],
    pantallas: [
      { ruta: '', titulo: 'Resumen', modulo: 11, menu: true },
      { ruta: 'reportes', titulo: 'Reportes', modulo: 12, menu: true },
      { ruta: 'viajes-flota', titulo: 'Viajes y flota', modulo: 11, menu: false },
      { ruta: 'catalogos', titulo: 'Catálogos', modulo: 10, menu: true },
      { ruta: 'cuentas', titulo: 'Cuentas', modulo: 10, menu: true },
    ],
  },
]

export function seccionPorId(id) {
  return SECCIONES.find((seccion) => seccion.id === id)
}

// Ruta absoluta de una pantalla dentro de su sección.
export function rutaCompleta(seccion, pantalla) {
  if (pantalla.ruta === '') return seccion.base
  return seccion.base === '/' ? `/${pantalla.ruta}` : `${seccion.base}/${pantalla.ruta}`
}

// Enlaces del menú de una sección (solo pantallas marcadas con menu: true).
export function enlacesMenu(idSeccion) {
  const seccion = seccionPorId(idSeccion)
  return seccion.pantallas
    .filter((pantalla) => pantalla.menu)
    .map((pantalla) => ({ to: rutaCompleta(seccion, pantalla), texto: pantalla.titulo }))
}
