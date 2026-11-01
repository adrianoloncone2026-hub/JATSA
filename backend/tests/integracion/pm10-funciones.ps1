# Funciones puras (sin base de datos ni red) del validador real pm10. Las usa validar-pm10.ps1 y las comprueba probar-pm10-funciones.ps1
# con datos sintéticos. Aquí vive la regla que separa DOS cosas distintas:
#   1) los DATOS ORIGINALES (filas, checksum y hash de cada fila de las 12 tablas): deben quedar IDÉNTICOS tras la limpieza;
#   2) los CONTADORES IDENTITY (IDENT_CURRENT): borrar filas no los restaura y una carrera fallida también puede consumir valores,
#      así que NO se promete que coincidan: solo se informa cuánto avanzaron, se exige que no retrocedan y se necesita aceptar de antemano
#      su avance irreversible.

# Tablas con IDENTITY que este módulo puede avanzar (las demás deben quedar con el mismo contador).
$script:TABLAS_CON_AVANCE_ESPERADO = @('Sucursal', 'Telefono_Sucursal', 'Ruta', 'Vehiculo', 'Persona', 'Usuario', 'Viaje')

# Orden de borrado respetando las claves foráneas: hijos antes que padres.
$script:ORDEN_LIMPIEZA = @('Envio_Viaje', 'Viaje', 'Telefono_Sucursal', 'Ruta', 'Usuario', 'Persona', 'Vehiculo', 'Sucursal')

function Get-OrdenLimpieza { $script:ORDEN_LIMPIEZA }

# Compara las filas, el checksum y el hash de cada fila de CADA tabla (sin mirar IDENT_CURRENT).
# Devuelve un objeto por tabla: Tabla, FilasAntes, FilasDespues, Identica.
function Get-ComparacionOriginales($antes, $despues) {
    foreach ($nombre in $antes.tablas.PSObject.Properties.Name) {
        $a = $antes.tablas.$nombre; $d = $despues.tablas.$nombre
        $identica = ($null -ne $d) -and ($a.filas -eq $d.filas) -and ([string]$a.checksum -eq [string]$d.checksum) -and ((@($a.hashes) -join ',') -eq (@($d.hashes) -join ','))
        [pscustomobject]@{ Tabla = $nombre; FilasAntes = $a.filas; FilasDespues = $(if ($null -ne $d) { $d.filas } else { $null }); Identica = [bool]$identica }
    }
}

# Clasifica el IDENT_CURRENT de cada tabla: SinCambio, Avanzo (esperado e informado) o RETROCEDIO (fallo: nadie debe reiniciar contadores).
# Una tabla fuera de TABLAS_CON_AVANCE_ESPERADO que avance se marca Inesperado: algo escribió donde no debía.
function Get-AvanceIdentity($antes, $despues) {
    foreach ($nombre in $antes.tablas.PSObject.Properties.Name) {
        $a = [long]($antes.tablas.$nombre.ident); $d = [long]($despues.tablas.$nombre.ident)
        $estado = if ($d -lt $a) { 'RETROCEDIO' } elseif ($d -eq $a) { 'SinCambio' } elseif ($script:TABLAS_CON_AVANCE_ESPERADO -contains $nombre) { 'Avanzo' } else { 'Inesperado' }
        [pscustomobject]@{ Tabla = $nombre; Antes = $a; Despues = $d; Avance = ($d - $a); Estado = $estado }
    }
}

# Validación del manifiesto (lo único que el script PHP puede borrar). Devuelve la lista de problemas (vacía = válido).
function Test-ManifiestoPm10($m) {
    $problemas = @()
    $reglas = @{ sucursales = '^PMX9\d{2}$'; vehiculos = '^PM1-9\d{2}$'; documentos = '^(9000010\d|2000000010\d)$'; usuarios = '^pm10_[a-z0-9]{3,20}$'; viajes = '^VJ\d{10}$' }
    foreach ($clave in $reglas.Keys) {
        foreach ($valor in @($m[$clave])) {
            if ($null -ne $valor -and [string]$valor -notmatch $reglas[$clave]) { $problemas += "$clave contiene un valor inesperado: $valor" }
        }
    }
    foreach ($t in @('Sucursal', 'Telefono_Sucursal', 'Ruta', 'Vehiculo', 'Persona', 'Usuario', 'Viaje', 'Envio_Viaje')) {
        if ($null -eq $m.identAntes -or $null -eq $m.identAntes[$t]) { $problemas += "falta identAntes de $t" }
    }
    $problemas
}