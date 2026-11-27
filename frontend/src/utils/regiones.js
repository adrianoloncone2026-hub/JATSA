const BASE = `${import.meta.env.BASE_URL}imagenes/regiones/`

const REGIONES = {
  amazonas: { nombre: 'Amazonas', lugar: 'Sarcófagos de Karajía' },
  ancash: { nombre: 'Áncash', lugar: 'Estela de Raimondi' },
  apurimac: { nombre: 'Apurímac', lugar: 'Piedra de Saywite' },
  arequipa: { nombre: 'Arequipa', lugar: 'Monasterio de Santa Catalina' },
  ayacucho: { nombre: 'Ayacucho', lugar: 'Templo del Sol, Vilcashuamán' },
  cajamarca: { nombre: 'Cajamarca', lugar: 'Kuntur Wasi' },
  callao: { nombre: 'Callao', lugar: 'Fortaleza del Real Felipe' },
  cusco: { nombre: 'Cusco', lugar: 'Machu Picchu' },
  huancavelica: { nombre: 'Huancavelica', lugar: 'Templo Inca de Huaytará' },
  huanuco: { nombre: 'Huánuco', lugar: 'Templo de Kotosh' },
  ica: { nombre: 'Ica', lugar: 'Arte textil Paracas' },
  junin: { nombre: 'Junín', lugar: 'Tunanmarca' },
  'la-libertad': { nombre: 'La Libertad', lugar: 'Huaca de la Luna' },
  lambayeque: { nombre: 'Lambayeque', lugar: 'Tumi de Oro' },
  lima: { nombre: 'Lima', lugar: 'Catedral de Lima', adicionales: ['lima-caral'] },
  'lima-caral': { nombre: 'Lima', lugar: 'Ciudad Sagrada de Caral' },
  loreto: { nombre: 'Loreto', lugar: 'Antiguo Hotel Palace' },
  'madre-de-dios': { nombre: 'Madre de Dios', lugar: 'Petroglifos de Pusharo' },
  moquegua: { nombre: 'Moquegua', lugar: 'Arquitectura de Moquegua' },
  pasco: { nombre: 'Pasco', lugar: 'Huarautambo' },
  piura: { nombre: 'Piura', lugar: 'Cerámica Vicus' },
  puno: { nombre: 'Puno', lugar: 'Chullpas de Sillustani' },
  'san-martin': { nombre: 'San Martín', lugar: 'Gran Pajatén' },
  tacna: { nombre: 'Tacna', lugar: 'Arco de Tacna' },
  tumbes: { nombre: 'Tumbes', lugar: 'Cabeza de Vaca' },
  ucayali: { nombre: 'Ucayali', lugar: 'Cerámica Shipibo' },
}

// "La Libertad" → "la-libertad"; "Áncash" → "ancash"
export function claveDeRegion(departamento) {
  return String(departamento ?? '')
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/\s+/g, '-')
}

// Fotos de un departamento: [{ clave, lugar, url, miniatura }]. Vacío si no hay fotografía para ese departamento.
export function fotosDeRegion(departamento) {
  const clave = claveDeRegion(departamento)
  const principal = REGIONES[clave]
  if (!principal) return []
  return [clave, ...(principal.adicionales ?? [])].map((c) => ({
    clave: c,
    lugar: REGIONES[c].lugar,
    url: `${BASE}${c}.jpg`,
    miniatura: `${BASE}miniaturas/${c}.jpg`,
  }))
}

export function fotoDeRegion(departamento) {
  return fotosDeRegion(departamento)[0] ?? null
}
